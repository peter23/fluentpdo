<?php

	$f = fopen('FluentPDO.php', 'w');
	fwrite($f, '<?php'."\n");

	$a = scandir('FluentPDO');
	foreach($a as $a1) {
		if(($a1 == '.') || ($a1 == '..')) {
			continue;
		}
		fwrite($f, "\n\n".'/** '."\n".' * '.$a1."\n".' */'."\n\n");
		$s = trim(file_get_contents('FluentPDO/'.$a1));
		$s = preg_replace('#(^|\n)[\x20\x09]*<\?php[\x20\x09]*\n#', '', $s);
		$s = preg_replace('#include_once\s+\'[^\']+\';[\x20\x09]*\n#', '', $s);
		fwrite($f, $s."\n\n");
	}

	fclose($f);
