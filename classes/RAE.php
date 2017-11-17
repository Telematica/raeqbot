<?php

/**
 * Created by PhpStorm.
 * User: TestingVM
 * Date: 2016-11-13
 * Time: 15:18
 */
class RAE {

	private static $staticData = array(
		"TS017111a7_id" => 3,
		"TS017111a7_cr"=> "74803ced6600a8a767b7ca27a60cae4c:eacd:DF2B25A9:806447578",
		"TS017111a7_76"=>0,
		"TS017111a7_86"=>0,
		"TS017111a7_md"=>1,
		"TS017111a7_rf"=>"http://dle.rae.es/",
		"TS017111a7_ct"=>0,
		"TS017111a7_pd"=>0
	);

	private static $tagWrappers = array(
		"em" => "_",
		"abbr" => "`"
	);

	private static $host = "http://dle.rae.es";

	public static function Search($word, $mode = "fetch"){
		$url = self::$host . "/srv/$mode?w=" . urlencode($word);
		$res = _HTTPRequest::POST($url, self::$staticData);
		//file_put_contents(dirname(__FILE__)."/../dump.txt", var_export($res, true));
		$res = mb_convert_encoding($res, 'HTML-ENTITIES', "UTF-8");
		$res = preg_replace('~[*`_\]\[]~', '', $res);
		return self::parseResult($res, $word);
	}

	private static function parseResult($result, $word){
		$doc =  new DOMDocument();
		libxml_use_internal_errors(true);
		$doc->loadHTML($result);
		libxml_clear_errors();

		if($doc->getElementsByTagName("article")->length > 0){
			return self::parseArticle($doc);
		}else if($doc->getElementsByTagName("ul")->length > 0){
			return self::parseSuggestions($doc->getElementsByTagName("ul")->item(0), $word);
		}else if($doc->getElementById("f0")){
			return array(new RAESearchResultItem("La palabra '$word' no fue encontrada en el diccionario."));
		}else{
			return self::Search($word, "search");
		}
	}

	private static function parseSuggestions(\DOMElement $rawData, $word){
		$results = array();
		array_push($results, new RAESearchResultItem("La palabra \"$word\" no fue encontrada.", "Sugerencias:"));
		foreach ($rawData->getElementsByTagName("li") as $li){
			array_push($results, new RAESearchResultItem($li->textContent));
		}
		return $results;
	}

	private static function parseArticle(\DOMDocument $rawData){
		$results = array();
		foreach($rawData->getElementsByTagName("article") as $article){
			$title = $article->getElementsByTagName("header")->item(0)->textContent;
			$description = array();
			foreach($article->getElementsByTagName("p") as $p){
				$desc = self::parseSentence($p);
				if(strpos($p->getAttribute("class"), "k") !== false){
					$desc = "*". $desc . "*";
				}
				array_push($description, $desc);
			}
			$description = implode("\r\n", $description);
			array_push($results, new RAESearchResultItem($title, $description));
		}
		return $results;
	}

	private static function parseSentence($node){
		$sentence = array();
		foreach($node->childNodes as $cNode){
			$val = $cNode->textContent;
			if(get_class($cNode) == "DOMElement"){
				if($cNode->tagName == "span"){
					$val = self::parseSentence($cNode);
					if($cNode->getElementsByTagName("a")->length == 0 && strpos($node->getAttribute("class"), "k") === false){
						$val = "_" . $val . "_";
					}
				}else if(isset(self::$tagWrappers[$cNode->tagName])){
					$val = self::$tagWrappers[$cNode->tagName] . $val . self::$tagWrappers[$cNode->tagName];
				}else if($cNode->tagName == "a"){
					$val = "[$val](" . self::$host . "/?" . str_replace("fetch?","",$cNode->getAttribute("href")) . ")";
				}
			}else if(get_class($cNode) == "DOMText" && preg_match('/^\d+\.\s$/', $val)){
				$val = "*$val*";
			}

			array_push($sentence, $val);
		}
		return implode(" ", $sentence);
	}

	private static function innerHTML(\DOMElement $element){
		$doc = $element->ownerDocument;
		$html = '';
		foreach ($element->childNodes as $node) {
			$html .= $doc->saveHTML($node);
		}
		return $html;
	}
}

class RAESearchResultItem {
	public $title, $description;

	public function __construct($title, $description = ""){
		$this->title = $title;
		$this->description = $description;
	}
}
