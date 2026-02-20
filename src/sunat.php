<?php
	namespace Sunat;
	class Sunat{
		var $cc;
		var $_legal = false;
		var $_trabs = false;
		var $_baseUrl = "https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/jcrS00Alias";
		var $_frameUrl = "https://e-consultaruc.sunat.gob.pe/cl-ti-itmrconsruc/FrameCriterioBusquedaWeb.jsp";
		var $_sessionReady = false;

		function __construct( $representantes_legales=false, $cantidad_trabajadores=false )
		{
			$this->_legal = $representantes_legales;
			$this->_trabs = $cantidad_trabajadores;

			$this->cc = new \cURL\cURL();
			$this->cc->setReferer( $this->_frameUrl );
			$this->cc->useCookie( true );
			$this->cc->setCookiFileLocation( __DIR__ . "/cookie.txt" );
		}

		/**
		 * Initialize SUNAT session by GETting the search form page.
		 * This establishes the JSESSIONID cookie required for all subsequent requests.
		 */
		function initSession()
		{
			if( $this->_sessionReady ) return true;
			// Clear stale cookies to get a fresh JSESSIONID
			$cookieFile = __DIR__ . "/cookie.txt";
			if( file_exists($cookieFile) ) file_put_contents($cookieFile, "");
			$this->cc->connect( $this->_frameUrl );
			if( $this->cc->getHttpStatus() == 200 )
			{
				$this->_sessionReady = true;
				return true;
			}
			return false;
		}

		/**
		 * Generate a random 52-char hex token.
		 * SUNAT's "reCAPTCHA v3" is a mock that generates random hex strings client-side.
		 * Their sunatrecaptcha3.js creates a fake grecaptcha object that just returns random hex.
		 * The server accepts any 52-char hex string as a valid token.
		 */
		function generateToken()
		{
			return bin2hex(random_bytes(26));
		}

		function search( $ruc )
		{
			if( strlen($ruc)!=8 && strlen($ruc)!=11 && !is_numeric($ruc) )
			{
				$response = new \response\obj(array(
					'success' => false,
					'message' => 'Formato RUC/DNI no validos.'
				));
				return $response;
			}
			if( strlen( $ruc )==11 && is_numeric($ruc) && !$this->valid( $ruc ) )
			{
				$response = new \response\obj(array(
					'success' => false,
					'message' => 'RUC no valido'
				));
				return $response;
			}

			if( strlen( $ruc ) == 8 && is_numeric($ruc) )
			{
				$ruc = $this->dnitoruc($ruc);
			}

			// Initialize session (GET form page to establish JSESSIONID)
			if( !$this->initSession() )
			{
				$response = new \response\obj(array(
					'success' 	=> 	false,
					'message' 	=> 	'No se pudo iniciar sesion con SUNAT.'
				));
				return $response;
			}

			$token = $this->generateToken();

			$data = array(
				"nroRuc"   => $ruc,
				"accion"   => "consPorRuc",
				"token"    => $token,
				"contexto" => "ti-it",
				"modo"     => "1"
			);

			$page = $this->cc->send( $this->_baseUrl, $data );
			if( $this->cc->getHttpStatus()==200 && !empty($page) )
			{
				$rtn = $this->parseRucResponse( $page, $ruc );

				if( $rtn !== false && count($rtn) > 2 )
				{
					$legal = array();
					if($this->_legal)
					{
						$razonSocial = isset($rtn["razon_social"]) ? $rtn["razon_social"] : "";
						$legal = $this->RepresentanteLegal( $ruc, $razonSocial );
					}
					$rtn["representantes_legales"] = $legal;

					$trabs = array();
					if($this->_trabs)
					{
						$trabs = $this->numTrabajadores( $ruc );
					}
					$rtn["cantidad_trabajadores"] = $trabs;
					$response = new \response\obj(array(
						'success' 	=> 	true,
						'result' 	=> 	$rtn
					));
					return $response;
				}
				$response = new \response\obj(array(
					'success' 	=> 	false,
					'message' 	=> 	'No se encontraron datos suficientes.'
				));
				return $response;
			}
			else
			{
				$response = new \response\obj(array(
					'success' 	=> 	false,
					'message' 	=> 	'No se pudo conectar a sunat.'
				));
				return $response;
			}
		}

		/**
		 * Parse the RUC response HTML using DOMDocument + DOMXPath.
		 * SUNAT changed from table-based HTML to Bootstrap list-groups (2024+).
		 *
		 * Structure:
		 * <div class="list-group-item">
		 *   <div class="row">
		 *     <div class="col-sm-5"><h4>Label:</h4></div>
		 *     <div class="col-sm-7"><p>Value</p></div>
		 *   </div>
		 * </div>
		 */
		function parseRucResponse( $html, $ruc )
		{
			if( empty($html) || strpos($html, 'Pagina de Error') !== false )
			{
				return false;
			}

			$doc = new \DOMDocument();
			$doc->preserveWhiteSpace = false;
			$previous = libxml_use_internal_errors(true);
			$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
			libxml_clear_errors();
			libxml_use_internal_errors($previous);

			$xpath = new \DOMXPath($doc);
			$data = array();

			// Extract all label:value pairs from Bootstrap list-group rows
			$items = $xpath->query('//div[contains(@class, "list-group-item")]//div[contains(@class, "row")]');
			if( !$items || $items->length === 0 )
			{
				return false;
			}

			foreach( $items as $row )
			{
				$cols = $xpath->query('.//div[contains(@class, "col-sm-")]', $row);
				if( !$cols || $cols->length < 2 ) continue;

				// Process pairs of columns (label, value)
				for( $i = 0; $i < $cols->length - 1; $i += 2 )
				{
					$label = $this->cleanText($cols->item($i)->textContent);
					$value = $this->cleanText($cols->item($i + 1)->textContent);

					if( !empty($label) && substr($label, -1) === ':' )
					{
						$data[$label] = $value;
					}
				}
			}

			if( empty($data) )
			{
				return false;
			}

			// Map SUNAT labels to our field names
			return $this->mapToFields( $data, $ruc );
		}

		/**
		 * Map raw label=>value pairs from SUNAT HTML to structured field names.
		 */
		function mapToFields( $data, $ruc )
		{
			// "Número de RUC:" contains "20516872307 - DISTRIBUIDORA JANDY SAC"
			$rucField = isset($data["N\xC3\xBAmero de RUC:"]) ? $data["N\xC3\xBAmero de RUC:"] : null;
			if( !$rucField ) $rucField = isset($data["Numero de RUC:"]) ? $data["Numero de RUC:"] : null;

			$razonSocial = "";
			if( $rucField )
			{
				$pos = strpos($rucField, '-');
				if( $pos !== false )
				{
					$razonSocial = trim(substr($rucField, $pos + 1));
				}
			}

			$rtn = array(
				"ruc"         => $ruc,
				"razon_social" => $razonSocial
			);

			// Map of field_name => possible SUNAT labels
			$fieldMap = array(
				"nombre_comercial"    => "Nombre Comercial:",
				"tipo"                => "Tipo Contribuyente:",
				"fecha_inscripcion"   => "Fecha de Inscripci\xC3\xB3n:",
				"estado"              => "Estado del Contribuyente:",
				"condicion"           => "Condici\xC3\xB3n del Contribuyente:",
				"direccion"           => "Domicilio Fiscal:",
				"sistema_emision"     => "Sistema de Emisi\xC3\xB3n de Comprobante:",
				"actividad_exterior"  => "Actividad de Comercio Exterior:",
				"sistema_contabilidad"=> "Sistema de Contabilidad:",
				"oficio"              => "Profesi\xC3\xB3n u Oficio:",
				"actividad_economica" => "Actividad(es) Econ\xC3\xB3mica(s):",
				"emision_electronica" => "Emisor electr\xC3\xB3nico desde:",
				"ple"                 => "Afiliado al PLE desde:",
			);

			foreach( $fieldMap as $field => $label )
			{
				if( isset($data[$label]) )
				{
					$val = $data[$label];
					// For estado and condicion, take only the first line
					if( $field === "estado" || $field === "condicion" )
					{
						$lines = preg_split('/[\r\n]+/', $val);
						$val = trim($lines[0]);
					}
					$rtn[$field] = $val;
				}
			}

			return $rtn;
		}

		/**
		 * Clean text: collapse whitespace and trim.
		 */
		function cleanText( $text )
		{
			$text = preg_replace('/\s+/', ' ', $text);
			return trim($text);
		}

		function numTrabajadores( $ruc )
		{
			$data = array(
				"accion" 	=> "getCantTrab",
				"nroRuc" 	=> $ruc,
				"desRuc" 	=> "",
				"contexto"  => "ti-it",
				"modo"      => "1"
			);
			$rtn = $this->cc->send( $this->_baseUrl, $data );
			if( $rtn!="" && $this->cc->getHttpStatus()==200 )
			{
				// Try parsing with DOMXPath first (new Bootstrap table)
				$result = $this->parseTrabajadoresHtml( $rtn );
				if( !empty($result) ) return $result;

				// Fallback: old regex pattern
				$patron = "/<td align='center'>(.*)-(.*)<\/td>[\t|\s|\n]+<td align='center'>(.*)<\/td>[\t|\s|\n]+<td align='center'>(.*)<\/td>[\t|\s|\n]+<td align='center'>(.*)<\/td>/";
				$output = preg_match_all($patron, $rtn, $matches, PREG_SET_ORDER);
				if( count($matches) > 0 )
				{
					$cantidad_trabajadores = array();
					$i = 1;
					foreach( $matches as $obj )
					{
						$cantidad_trabajadores['p'.$i]=array(
							"periodo" 				=> $obj[1]."-".$obj[2],
							"anio" 					=> $obj[1],
							"mes" 					=> $obj[2],
							"total_trabajadores" 	=> $obj[3],
							"pensionista" 			=> $obj[4],
							"prestador_servicio" 	=> $obj[5]
						);
						$i++;
					}
					return $cantidad_trabajadores;
				}
			}
			return array();
		}

		/**
		 * Parse trabajadores HTML table using DOMXPath.
		 */
		function parseTrabajadoresHtml( $html )
		{
			if( empty($html) ) return array();

			$doc = new \DOMDocument();
			$doc->preserveWhiteSpace = false;
			$previous = libxml_use_internal_errors(true);
			$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
			libxml_clear_errors();
			libxml_use_internal_errors($previous);

			$xpath = new \DOMXPath($doc);
			$rows = $xpath->query('//table[contains(@class, "table")]//tbody//tr');

			if( !$rows || $rows->length === 0 ) return array();

			$cantidad_trabajadores = array();
			$i = 1;
			foreach( $rows as $row )
			{
				$cells = $xpath->query('.//td', $row);
				if( !$cells || $cells->length < 4 ) continue;

				$periodo = $this->cleanText($cells->item(0)->textContent);
				$parts = explode('-', $periodo);
				$anio = isset($parts[0]) ? trim($parts[0]) : '';
				$mes = isset($parts[1]) ? trim($parts[1]) : '';

				$cantidad_trabajadores['p'.$i] = array(
					"periodo"             => $periodo,
					"anio"                => $anio,
					"mes"                 => $mes,
					"total_trabajadores"  => $this->cleanText($cells->item(1)->textContent),
					"pensionista"         => $this->cleanText($cells->item(2)->textContent),
					"prestador_servicio"  => $this->cleanText($cells->item(3)->textContent)
				);
				$i++;
			}
			return $cantidad_trabajadores;
		}

		function RepresentanteLegal( $ruc, $razonSocial = "" )
		{
			$data = array(
				"accion" 	=> "getRepLeg",
				"nroRuc" 	=> $ruc,
				"desRuc" 	=> $razonSocial,
				"contexto"  => "ti-it",
				"modo"      => "1"
			);
			$rtn = $this->cc->send( $this->_baseUrl, $data );
			if( $rtn!="" && $this->cc->getHttpStatus()==200 )
			{
				// Parse with DOMXPath (new Bootstrap table with thead/tbody)
				$result = $this->parseRepLegalHtml( $rtn );
				if( !empty($result) ) return $result;

				// Fallback: old regex pattern
				$patron = '/<td class=bg align="left">[\t|\s|\n]+(.*)<\/td>[\t|\s|\n]+<td class=bg align="center">[\t|\s|\n]+(.*)<\/td>[\t|\s|\n]+<td class=bg align="left">[\t|\s|\n]+(.*)<\/td>[\t|\s|\n]+<td class=bg align="left">[\t|\s|\n]+(.*)<\/td>[\t|\s|\n]+<td class=bg align="left">[\t|\s|\n]+(.*)<\/td>/';
				$output = preg_match_all($patron, $rtn, $matches, PREG_SET_ORDER);
				if( count($matches) > 0 )
				{
					$representantes_legales = array();
					$i = 1;
					foreach( $matches as $obj )
					{
						$representantes_legales['r'.$i]=array(
							"tipodoc" 				=> trim($obj[1]),
							"numdoc" 				=> trim($obj[2]),
							"nombre" 				=> trim($obj[3]),
							"cargo" 				=> trim($obj[4]),
							"desde" 				=> trim($obj[5]),
						);
						$i++;
					}
					return $representantes_legales;
				}
			}
			return array();
		}

		/**
		 * Parse representantes legales HTML table using DOMXPath.
		 * SUNAT returns: <table class="table"><thead>...</thead><tbody><tr><td>...</td></tr></tbody></table>
		 */
		function parseRepLegalHtml( $html )
		{
			if( empty($html) || strpos($html, 'Pagina de Error') !== false ) return array();

			$doc = new \DOMDocument();
			$doc->preserveWhiteSpace = false;
			$previous = libxml_use_internal_errors(true);
			$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
			libxml_clear_errors();
			libxml_use_internal_errors($previous);

			$xpath = new \DOMXPath($doc);
			$rows = $xpath->query('//table[contains(@class, "table")]//tbody//tr');

			if( !$rows || $rows->length === 0 ) return array();

			$representantes_legales = array();
			$i = 1;
			foreach( $rows as $row )
			{
				$cells = $xpath->query('.//td', $row);
				if( !$cells || $cells->length < 5 ) continue;

				$representantes_legales['r'.$i] = array(
					"tipodoc" => $this->cleanText($cells->item(0)->textContent),
					"numdoc"  => $this->cleanText($cells->item(1)->textContent),
					"nombre"  => $this->cleanText($cells->item(2)->textContent),
					"cargo"   => $this->cleanText($cells->item(3)->textContent),
					"desde"   => $this->cleanText($cells->item(4)->textContent),
				);
				$i++;
			}
			return $representantes_legales;
		}

		function dnitoruc($dni)
		{
			if ($dni!="" || strlen($dni) == 8)
			{
				$suma = 0;
				$hash = array(5, 4, 3, 2, 7, 6, 5, 4, 3, 2);
				$suma = 5; // 10[NRO_DNI]X (1*5)+(0*4)
				for( $i=2; $i<10; $i++ )
				{
					$suma += ( $dni[$i-2] * $hash[$i] ); //3,2,7,6,5,4,3,2
				}
				$entero = (int)($suma/11);

				$digito = 11 - ( $suma - $entero*11);

				if ($digito == 10)
				{
					$digito = 0;
				}
				else if ($digito == 11)
				{
					$digito = 1;
				}
				return "10".$dni.$digito;
			}
			return false;
		}
		function valid($valor) // Script SUNAT
		{
			$valor = trim($valor);
			if ( $valor )
			{
				if ( strlen($valor) == 11 ) // RUC
				{
					$suma = 0;
					$x = 6;
					for ( $i=0; $i<strlen($valor)-1; $i++ )
					{
						if ( $i == 4 )
						{
							$x = 8;
						}
						$digito = $valor[$i];
						$x--;
						if ( $i==0 )
						{
							$suma += ($digito*$x);
						}
						else
						{
							$suma += ($digito*$x);
						}
					}
					$resto = $suma % 11;
					$resto = 11 - $resto;
					if ( $resto >= 10)
					{
						$resto = $resto - 10;
					}
					if ( $resto == $valor[strlen($valor)-1] )
					{
						return true;
					}
				}
			}
			return false;
		}
	}
