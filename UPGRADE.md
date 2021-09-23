Upgrade
=======

Version 1 to Version 2
----------------------

`Client::forceHttpVersion10` has been removed in favor of `Client::addCurlOptions([CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0])`.
