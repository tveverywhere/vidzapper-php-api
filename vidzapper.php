<?php
    require_once("vidzapper.api.php");
    $vidzapper_api = 'your api key'; 
    $vidzapper_key = 'your secret'; 
    $playlist_id = 'you playlist id'; 

    $vidzapper = new VidZapper(array(
	 'appId' => $vidzapper_api,
	'secret' => $vidzapper_key,
    	'api'=>'https://vzconsole.com/live/api/',
      'cache'=>30, /*30 Minutes*/
    )); 

    global $vz;
    $vz = new stdClass;
    $vz->Playlists= $vidzapper->fetch("playlist",array("\$orderby"=>"ID","\$top"=>10));
?>