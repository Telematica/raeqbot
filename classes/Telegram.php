<?php

class Telegram {
	private static $tbBaseUrl = "https://api.telegram.org", $tbBotId = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";

	public static function Debug($msg){
		file_put_contents(dirname(__FILE__)."/../dump.txt", var_export($msg, true));
		$tbDebugChatId = 12345678;
		$data = array(
			"chat_id" => $tbDebugChatId,
			"text" => $msg
		);
		$tbFullUrl = self::$tbBaseUrl . "/bot" . self::$tbBotId . "/sendMessage";
		return _HTTPRequest::POST($tbFullUrl,$data);

	}

	public static function ManageRequest(){
		$_REQUEST = json_decode(file_get_contents('php://input'),true);
		if(!isset($_REQUEST["inline_query"])) return;
		self::processRequest($_REQUEST["inline_query"]);
	}

	private static function processRequest($input){
		$termId = false;
		$input["query"] = $input["query"] ? trim(strtolower($input["query"])) : "";
		if(strlen($input["query"]) < 1){
			$response = new InlineQueryResponse($input["id"], array());
		}else {
			$termId = self::getTermId($input["query"]);
			if($termId){
				$resultFromDb = true;
				$results = self::buildInlineQueryResponseFromDb($termId);
			}else{
				$results = self::formatResults(RAE::Search($input["query"]));
			}
			$response = new InlineQueryResponse($input["id"], $results);
		}
		$result = self::AnswerInlineQuery($response);
		if(strlen($input["query"]) > 0 && count($response->results) > 0 &&
		   !!$response->results[0]->description &&
		   $response->results[0]->description !== "Sugerencias:"){
			self::SaveToDatabase($input, $response, $result, $termId);
		}
	}

	private function getTermId($term){
		$dbo = new DBO();
		$res = $dbo->Select("term")->Where(array("text", "=", $term))->Exec();
		if(!$res) return false;
		return $res[0]->Id;
	}

	private function buildInlineQueryResponseFromDb($termId){
		$data = (new DBO())->Select("term_result")
						   ->Where(array("term_id", "=", $termId))
						   ->OrderBy(array("position", "desc"))
						   ->Exec();
		$formatedResults = array();
		foreach($data as $d){
			$title = $d->Title;
			$inputMessage = new InputTextMessageContent($d->Content,ParseMode::MARKDOWN);
			$description = $d->Description;
			$iqra = new InlineQueryResultArticle($title, $inputMessage, $description);
			array_push($formatedResults, $iqra);
		}
		return $formatedResults;
	}

	private function SaveToDatabase($input, $response, $result, $termId){
		if(!$termId){
			$termObj = Model::Create("term");
			$termObj->Text = $input["query"];
			$termObj->Save();
			foreach ($response->results as $position => $iqra) {
				Model::Create("term_result", array(
					"TermId" => $termObj->Id,
					"Position" => $position,
					"Title" => $iqra->title,
					"Content" => $iqra->input_message_content->message_text,
					"Description" => $iqra->description
				))->Save();
			}
		}
		Model::Create("query", array(
			"TermId" => $termId ?: $termObj->Id,
			"UserIdHash" => md5($input["from"]["id"]),
			"Result" => $result,
			"FromCache" => !!$termId
		))->Save();
	}

	private static function formatResults($raeResults){
		$formatedResults = array();
		foreach($raeResults as $raeResult){
			$title = trim(preg_replace('/\s+/', ' ',$raeResult->title));
			$inputMessage = new InputTextMessageContent("*$title* \r\n $raeResult->description",ParseMode::MARKDOWN);
			$description = preg_replace('/([*`_\]\[])|(\(http.+\))|(\\r\\n)|(\')/', '', $raeResult->description);
			$iqra = new InlineQueryResultArticle($title, $inputMessage, $description);
			array_push($formatedResults, $iqra);
		}
		return $formatedResults;
	}

	public static function AnswerInlineQuery(\InlineQueryResponse $response){
		$tbFullUrl = self::$tbBaseUrl . "/bot" . self::$tbBotId . "/answerInlineQuery";
		$res = _HTTPRequest::POST($tbFullUrl, $response, "application/json");
		if($json = json_decode($res)){
			return $json->ok;
		}
		return false;
	}

}

class InlineQueryResponse {
	public $inline_query_id, $results;//, $cache_time, $is_personal, $next_offset, $switch_pm_text, $switch_pm_parameter;

	public function __construct($inlineQueryId, $results){
		$this->inline_query_id = $inlineQueryId;
		$this->results = $results;//json_encode($results);
	}
}

class InlineQueryResultArticle{
	public $type, $id, $title, $input_message_content, /*$reply_markup, $url, $hide_url,*/ $description/*,
		$thumb_url, $thumb_width, $thumb_height*/;

	public function __construct($title, $inputMessageContent = "", $description = ""){
		$this->type = "article";
		$this->id = $this->guid();
		$this->title = $title;
		$this->input_message_content = $inputMessageContent;
		$this->description = $description; // Hasta 113 caracteres
	}

	private function guid(){
		if (function_exists('com_create_guid') === true){
			return trim(com_create_guid(), '{}');
		}
		return sprintf('%04X%04X-%04X-%04X-%04X-%04X%04X%04X', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
	}
}

class InputTextMessageContent{
	public $message_text/*, $parse_mode, $disable_web_page_preview*/;

	public function __construct($messageText, $parseMode = null, $disableWebPagePreview = ""){
		$this->message_text = $messageText;
		if($parseMode) $this->parse_mode = $parseMode;
		/*$this->disable_web_page_preview = $disableWebPagePreview;*/
	}
}

class ParseMode {
	const MARKDOWN = "Markdown";
	const HTML = "HTML";
	const NONE = "";
}
