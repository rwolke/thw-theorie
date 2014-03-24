<?php
	$urls = array();
	for($i=1;$i<=11; $i++)
		$urls[$i] = 'http://www.thw-theorie.de/loesung/abschnitt-'.$i.'.html';
		
	function returnSection($text, &$skip, $begin, $end)
	{
		$p = strpos($text, $begin, $skip);
		$p2 = strpos($text, $end, $p);
		if($p === false || $p2 === false)
			return false;
		
		$skip = $p2;
		return substr($text, $p, $p2-$p);
	}
	// replace  
	function r($in) {
		return strtr($in, array(
		));
	}
	
	$category = array();
	$questions = array();
	foreach($urls AS $cat => $url)
	{
		echo 'Getting '.$url.' ... ';
		flush();
		$c = file_get_contents($url);
		
		$p = strpos($c, '<h1>');
		$s = returnSection($c, $p, '<b>', '</b>');
		$category[$cat] = array(
			'name' => html_entity_decode(strip_tags($s), ENT_COMPAT, 'UTF-8')
		);
		
		$tableP = 0;
		while($t = returnSection($c, $tableP, '<table class="fragebkg"', '</table>'))
		{
			$cont = array();
			$rowP = 0;
			while($r = returnSection($t, $rowP, '<tr', '</tr>'))
			{
				$row = array();
				$colP = 0;
				while($f = returnSection($r, $colP, '<td', '</td>'))
					$row[] = $f;
				$cont[] = $row;
			}
			
			$q_name = explode('.', strip_tags($cont[0][0]));
			$q = array(
				'category' => intval($q_name[0]),
				'number' => intval($q_name[1]),
				'question' => r(html_entity_decode(strip_tags($cont[0][1]), ENT_COMPAT, 'UTF-8')),
				'answers' => array(),
				'correct' => array()
			);
			for($i = 0; $i<=3; $i++)
				if(isset($cont[$i][$i?0:2]))
				{
					$q['answers'][$i+1] = r(html_entity_decode(strip_tags($cont[$i][$i?0:2]), ENT_COMPAT, 'UTF-8'));
					if(strpos($cont[$i][$i?0:2], 'class="korrekt"'))
						$q['correct'][] = $i+1;
				}
			
			$questions[] = $q;
		}
		echo 'done!'."\n";
		flush();
	}
	$export = array(
		'version' => time(),
		'category' => $category,
		'questions' => $questions
	);
	
	file_put_contents('questions.json', json_encode($export));
?>