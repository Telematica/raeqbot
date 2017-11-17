<?php

class _HTTPRequest{

	public static function POST($url,$data = null, $contentType = "application/x-www-form-urlencoded"){

		if(strpos($contentType, "json") && $data){
			$data = json_encode($data);
		}else{
			$data = $data ? http_build_query($data) : "";
		}

		$options = array(
			'http' => array(
				'header'  => "Content-type: $contentType\r\n",
				'method'  => 'POST',
				'ignore_errors' => true,
				'content' => $data
			)
		);

		$context  = stream_context_create($options);
		$result = file_get_contents($url, false, $context);
		return $result;
	}

	public static function GET($url,$data = null){
		$options = array(
			'http' => array(
				'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
				'method'  => 'GET',
				'content' => $data ? http_build_query($data) : ""
			)
		);
		$context  = stream_context_create($options);
		$result = file_get_contents($url, false, $context);
		return $result;
	}
}
