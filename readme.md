VidZapper PHP API
------------------------

VidZapper PHP API Library

```php

include_once("vidzapper/vidzapper.api.php");


$vidzapper = new VidZapper(array(
  'api'=> 'https://live.vzconsole.com/api/',
	'appId' => 'Your Api Key',
	'secret' => 'Your Api Secret',
  'debug'=> false
)); 
```

examplae to get all navs

```php
$navs=$vidzapper->v2("library/navs/12/all",'GET', array("\$orderby"=>"ParentID,Sequence"),false);
```

you can also use following example with OAuth2 generic provider

https://github.com/thephpleague/oauth2-client

```php

$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId'                => 'YourAppId',    // The client ID assigned to you by the provider
    'clientSecret'            => 'YouApp Secret',   // The client password assigned to you by the provider
    'redirectUri'             => 'http://'.$_SERVER["HTTP_HOST"].'/account/signin',
    'urlAuthorize'            => 'https://'.$vz_server.'/oauth/authorize',
    'urlAccessToken'          => 'https://'.$vz_server.'/token',
    'urlResourceOwnerDetails' => 'https://'.$vz_server.'/api/v2/my/util/about',
    'scopes'                  => 'basic',
    'verify'                  => false
]);

```
