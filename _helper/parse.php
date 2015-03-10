<?php
	
	if(!file_exists($argv[1]))
		die('PDF-Datei "'.$argv[1].'" nicht gefunden!'."\n");
	
	exec('pdftotext -bbox "'.$argv[1].'" fragen.xml');
	
	$xml = simplexml_load_file('fragen.xml');
	$XMLpages = $xml->body->doc->page;
	
	$pages = [];
	foreach($XMLpages AS $p) {
		$page = [];
		foreach($p->word as $w)
			$page[] = [
				floatval($w['xMin']), 
				floatval($w['yMin']), 
				strval($w), 
				floatval($w['xMax']) - floatval($w['xMin'])
			];
		$pages[] = $page;
	}
	
	define('X', 0);
	define('Y', 1);
	define('T', 2);
	define('L', 3);
	
	class Page {
		private $_words = [];
		private $_columns = [];
		private $_tableHeader = false;
		private $_yMin = 0;
		private $_yMax = 0;
		
		private function _findLayout() {
			$this->_yMax = 0;
			foreach($this->_words AS $w)
				if($w[T] == 'Nr.' && $w[X] < 100) {
					$this->_tableHeader = [X => $w[X], Y => $w[Y]];
					$this->_yMin = $w[Y] + 5;
				}
				elseif($w[T] == 'C' && $w[X] > 480)
					$this->_yMax = max($this->_yMax, $w[Y]);
			
			$footerY = 0;
			foreach($this->_words AS $w)
				if($w[T] == 'Referat' && $w[Y] > $this->_yMax)
					$footerY = $w[Y];
			
			foreach($this->_words AS $w)
				if($w[Y] < $footerY - 5)
					$this->_yMax = $w[Y];
		}
		
		private function _countElementsOnAxis(&$elements, $axis, $weightCB) {
			$count = [];
			foreach($elements AS $e) {
				$found = 0;
				foreach($count AS $k => $v)
					if($v[0] == $e[$axis])
						$found = $count[$k][1] += call_user_func($weightCB, $e[T]);
				if(!$found)
					$count[] = [$e[$axis], call_user_func($weightCB, $e[T])];
			}
			return $count;
		}
		private function _consolidate(&$x, $maxDiff = 1.0) {
			$this->_weightSort($x);
			$list = array_keys($x);
			while(count($list) >= 2) {
				$i = array_shift($list);
				foreach($list AS $k => $j)
					if($x[$j][1] && abs($x[$i][0] - $x[$j][0]) < $maxDiff) {
						$x[$i][0] = ($x[$i][0] * $x[$i][1] + $x[$j][0] * $x[$j][1]) / ($x[$i][1] + $x[$j][1]);
						$x[$i][1]+= $x[$j][1];
						unset($x[$j]);
						unset($list[$k]);
					}
			}
		}
		private function _weightSort(&$array) {
			usort($array, function($a,$b){
				return $a[1] == $b[1] ? 0 : ($a[1] < $b[1] ? 1 : -1);
			});
		}
		private function _getConsensus($array) {
			$this->_consolidate($array);
			$this->_weightSort($array);
			$v = reset($array);
			return $v[0];
		}
		private function _getWeight($word) {
			if($word == 'X')
				return 3;
			if($word == 'A' || $word == 'B' || $word == 'C')
				return 2;
			return 1;
		}
		private function _findAndSaveColums($num) {
			$this->_columns[0] = $this->_tableHeader[X] - 5;
			
			$frage = null;
			$aw = null;
			foreach($this->_words AS $w)
				if($w[T] == 'Frage' && $w[Y] >= $this->_tableHeader[Y] - 5 && $w[Y] <= $this->_tableHeader[Y] + 5)
					$this->_columns[1] = $w[X] - 5;
				elseif($w[T] == 'Antwortmöglichkeit(en)' && $w[Y] >= $this->_tableHeader[Y] - 5 && $w[Y] <= $this->_tableHeader[Y] + 5) 
					$this->_columns[2] = $w[X] - 5;
			
			$abc = [];
			$xxx = [];
			foreach($this->_words AS $w) {
				if($w[X] > $this->_columns[2]) {
					if($w[T] == 'X')
						$xxx[] = [$w[X], 1];
					elseif(strpos('ABC', $w[T]) !== false)
						$abc[] = [$w[X], 1];
				}
			}
				
			$this->_columns[3] = $this->_getConsensus($abc);
			$this->_columns[4] = $this->_getConsensus($xxx);
		}
		
		private function _extractColumnElements($column) {
			assert(isset($this->_columns[$column]));
			$xMin = $this->_columns[$column] - 0.5;
			$xMax = isset($this->_columns[$column+1]) ? $this->_columns[$column+1] - 1 : $xMin + 50;
			$yMin = $this->_yMin;
			$yMax = $this->_yMax + 5;
			
			return array_filter($this->_words, function($w) use($xMin,$xMax,$yMin,$yMax) {
				return $w[X] >= $xMin && $w[X] < $xMax && $w[Y] >= $yMin && $w[Y] < $yMax; 
			});
		}
		private function _findLineHeight() {
			$text = $this->_extractColumnElements(2);
			$diff = [];
			$n = count($text);
			$k = array_keys($text);
			for($i = 1; $i < $n; $i++) {
				$d = $text[$k[$i]][Y] - $text[$k[$i-1]][Y];
				if($d > 0.2 && $d < 25)
					$diff[] = [$d, 1];
			}
			
			$this->_lineHeight = $this->_getConsensus($diff);
		}
		
		public function __construct($page) {
			$this->_words = $page;
			usort($this->_words, function($a,$b){
				return $a[Y] == $b[Y] ? (
					$a[X] == $b[X] ? 0 : (
						$a[X] < $b[X] ? -1 : 1
					) 
				) : (
					$a[Y] < $b[Y] ? -1 : 1
				);
			});
			$this->_findLayout();
			$this->_findAndSaveColums(5);
			$this->_findLineHeight();
		}
		
		private function _joinText($elements) {
			$lines = array();
			$count = $this->_countElementsOnAxis($elements, Y, function($a){return 1;});
			$this->_consolidate($count);
			
			$ys = [];
			foreach($count AS $c)
				$ys[] = $c[0];
			sort($ys);
			
			foreach($elements AS $e) {
				$diff = 999;
				$row = -1;
				foreach($ys AS $r => $y)
					if(abs($y - $e[Y]) < $diff) {
						$row = $r;
						$diff = abs($y - $e[Y]);
					}
					
				$lines[$row][intval($e[X]*100)] = $e[T];
			}
			
			foreach($lines AS $i => $l)
				$lines[$i] = implode(' ', $l);
			
			$text = reset($lines);
			for($i = 1; $i < count($lines); $i++) {
				if(substr($text, -1) == '-') {
					if(strtolower(substr($lines[$i], 0, 1)) == substr($lines[$i], 0, 1))
					{
						$p = strpos($lines[$i], ' ');
						$w = $p ? substr($lines[$i], 0, $p) : $lines[$i];
						if($w == 'und' || $w == 'oder')
							$text.= ' '.$lines[$i];
						else
							$text = substr($text, 0, -1).$lines[$i];
					}
					else
						$text.= $lines[$i];
				}
				elseif(substr($text, -1) == '/')
					$text.= $lines[$i];
				else
					$text.= ' '.$lines[$i];
			}
			return $text;
		}
		
		public function getCategory() {
			$lernabschnitt = false;
			foreach($this->_words AS $w)
				if($w[T] == 'Lernabschnitt' && $w[Y] < $this->_yMin)
					$lernabschnitt = $w;
			
			$lernabschnittNr = false;
			foreach($this->_words AS $w)
				if(
					$w[X] > $lernabschnitt[X] + $lernabschnitt[L] && 
					$w[X] < $lernabschnitt[X] + $lernabschnitt[L] + 50 && 
					$w[Y] > $lernabschnitt[Y] - 3 && 
					$w[Y] < $lernabschnitt[Y] + 3
				) {
					$lernabschnittNr = $w;
					break;
				}
			
			$text = [];
			foreach($this->_words AS $w)
				if(
					$w[Y] > $lernabschnitt[Y] + 5 &&
					$w[Y] < $this->_yMin - 10
				)
					$text[] = $w;
			
			return [$lernabschnittNr[T], $this->_joinText($text)];
		}
		
		private function _getIndexForText(&$list, $text) {
			$selDiff = 999;
			$selF = -1;
			foreach($list AS $i => $f) {
				if($text[Y] >= $f['_yMin'] && $text[Y] <= $f['_yMax'])
					return $i;
				if(abs($text[Y] - $f['_yMin']) < $selDiff) {
					$selF = $i;
					$selDiff = abs($text[Y] - $f['_yMin']);
				}
				if(abs($text[Y] - $f['_yMax']) < $selDiff) {
					$selF = $i;
					$selDiff = abs($text[Y] - $f['_yMax']);
				}
			}
			
//			$fragen[$selF]['_yMin'] = min($text[Y], $fragen[$selF]['_yMin']);
//			$fragen[$selF]['_yMax'] = max($text[Y], $fragen[$selF]['_yMax']);
			return $selF;
		}
		
		private function _getAnswerIndexForText(&$answers, $text) {
			$selDiff = 999;
			$selAW = -1;
			foreach($answers AS $abc => $y)
				if(abs($text[Y] - $y) < $selDiff) {
					$selAW = $abc;
					$selDiff = abs($text[Y] - $y);
				}
			return $selAW;
		}

		public function getQuestions() {
			$translate = ['A' => '1', 'B' => '2', 'C' => '3'];
			
			$fragen = [];
			$start = $this->_tableHeader[Y] + $this->_lineHeight / 2;
			foreach($this->_extractColumnElements(0) AS $nr)
			{
				list($cat, $num) = explode('.', $nr[T]);
				$hHalb = ($nr[Y] - $start);
				$start += 2 * $hHalb;
				$fragen[] = [
					'_nr' => $nr[T],
					'category' => intval($cat),
					'number' => intval($num),
					'question' => '',
					'answers' => '',
					'correct' => [],
					'_text' => [],
					'_AWs' => [],
					'_yMin' => $nr[Y] - $hHalb,
					'_yMax' => $nr[Y] + $hHalb,
					'_yABC' => []
				];
			}
			
			foreach($this->_extractColumnElements(3) AS $abc) {
				$selF = $this->_getIndexForText($fragen, $abc);
				
				$start = $fragen[$selF]['_yMin'];
				if(count($fragen[$selF]['_yABC'])) {
					$last = end($fragen[$selF]['_yABC']);
					$start = $last['_yMax'];
				}
				
				$fragen[$selF]['_yABC'][$abc[T]] = [
					'_yMin' => $start,
					'_yMax' => $start + 2 * ($abc[Y] - $start)
				];
			}
			
			foreach($this->_extractColumnElements(1) AS $text) {
				$selF = $this->_getIndexForText($fragen, $text);
				$fragen[$selF]['_text'][] = $text;
			}
			
			foreach($this->_extractColumnElements(2) AS $text) {
				$selF = $this->_getIndexForText($fragen, $text);
				$selAW = $this->_getIndexForText($fragen[$selF]['_yABC'], $text);
				
				if(!isset($fragen[$selF]['_AWs'][$selAW]))
					$fragen[$selF]['_AWs'][$selAW] = [];
				$fragen[$selF]['_AWs'][$selAW][] = $text;
			}
			foreach($this->_extractColumnElements(4) AS $aw) {
				$selF = $this->_getIndexForText($fragen, $aw);
				$selAW = $this->_getIndexForText($fragen[$selF]['_yABC'], $aw);
				$fragen[$selF]['correct'][] = intval($translate[$selAW]);
			}
			
			foreach(array_keys($fragen) AS $i) {
				$fragen[$i]['question'] = $this->_joinText($fragen[$i]['_text']);
				$fragen[$i]['answers'] = [];
				
				foreach($fragen[$i]['_AWs'] AS $k => $AW)
					$fragen[$i]['answers'][$translate[$k]] = $this->_joinText($AW);
			
				foreach(array_keys($fragen[$i]) AS $k)
					if(substr($k, 0, 1) == '_')
						unset($fragen[$i][$k]);
			}
			
			return $fragen;
		}
	}
	
	$catalog = [];
	$cats = [];
	foreach($pages AS $page) {
		$p = new Page($page);
		$qs = $p->getQuestions();
		$cats[] = $p->getCategory();
		
		foreach($qs AS $q)
			$catalog[] = $q;
	}
	$categories = [];
	foreach($cats AS $c)
		$categories[$c[0]] = ['name' => $c[1]];
	
	$data = [
		'version' => time(),
		'category' => $categories,
		'questions' => $catalog
	];
	file_put_contents($argv[1].'.json', json_encode($data, JSON_PRETTY_PRINT));
	
	unlink('fragen.xml');
?>