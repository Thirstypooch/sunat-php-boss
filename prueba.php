<?php
	require_once("./src/autoload.php");

	$company = new \Sunat\Sunat( true, true );

	// Test with known RUC (DISTRIBUIDORA JANDY SAC)
	$ruc = "20516872307";

	echo "=== Buscando RUC: $ruc ===\n\n";
	$search = $company->search( $ruc );

	if( $search->success == true )
	{
		echo "EXITO!\n\n";
		echo $search->json( null, true );
	}
	else
	{
		echo "ERROR: " . $search->message . "\n";
	}

	echo "\n\n";

	// Test with original RUC from boss's code
	$ruc2 = "20169004359";
	echo "=== Buscando RUC: $ruc2 ===\n\n";
	$search2 = $company->search( $ruc2 );

	if( $search2->success == true )
	{
		echo "EXITO!\n\n";
		echo $search2->json( null, true );
	}
	else
	{
		echo "ERROR: " . $search2->message . "\n";
	}
