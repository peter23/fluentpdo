<?php

	require('concatToOneFile.php');

	require('FluentPDO.php');

	$fpdo = new FluentPDO('mysql:host=localhost;dbname=test;charset=utf8', 'root', '');

	$q = $fpdo->from('sometable')->where('1=1');
	echo $q->getQuery(),"\n";
	var_dump($q->fetchAll());
	echo "\n\n";

	$q = $fpdo->from('sometable')->select('count(1)')->where('1=1');
	echo $q->getQuery(),"\n";
	var_dump($q->fetchAll());
	echo "\n\n";

	$q = $fpdo->query('SELECT * FROM sometable WHERE x=?', array('1'));
	echo $q->getQuery(),"\n";
	var_dump($q->fetchAll());
	echo "\n\n";
