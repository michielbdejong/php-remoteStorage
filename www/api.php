<?php

require_once "../lib/Config.php";
require_once "../lib/Logger.php";
require_once "../lib/Http/Uri.php";
require_once "../lib/Http/HttpRequest.php";
require_once "../lib/Http/HttpResponse.php";
require_once "../lib/Http/IncomingHttpRequest.php";
require_once "../lib/OAuth/RemoteResourceServer.php";
require_once "../lib/Storage/RemoteStorageRequest.php";
require_once "../lib/Storage/RemoteStorageException.php";

$remoteStorageVersion = "remoteStorage.2012.10";

$response = new HttpResponse();
$response->setHeader("Content-Type", "application/json");
$response->setHeader("Access-Control-Allow-Origin", "*");
$response->setHeader("X-RemoteStorage-Version", $remoteStorageVersion);

try { 
    $config = new Config(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "remoteStorage.ini");
    $rootDirectory = $config->getValue('filesDirectory');
    $request = RemoteStorageRequest::fromIncomingHttpRequest(new IncomingHttpRequest());

    $rs = new RemoteResourceServer($config->getValue("oauthTokenEndpoint"));

    if("OPTIONS" === $request->getRequestMethod()) {
        $response->setHeader("Access-Control-Allow-Origin", "*");
        $response->setHeader("Access-Control-Allow-Headers", "Content-Type, Authorization, Origin, If-None-Match, If-Match");
        $response->setHeader("Access-Control-Allow-Methods", "GET, PUT, DELETE, HEAD");
    } else if($request->isPublicRequest() && !$request->headerExists("HTTP_AUTHORIZATION")) { 
        // only GET of item is allowed, nothing else
        if($request->isDirectoryRequest()) {
            throw new RemoteStorageException("invalid_request", "not allowed to list contents of public folder");
        }
        if ($request->getRequestMethod() != 'HEAD' && $request->getRequestMethod() != 'GET') {
            throw new RemoteStorageException("invalid_request", "only GET and HEAD allowed");
        }
        // public but not listing, return file if it exists...
        $file = realpath($rootDirectory . $request->getPathInfo());
        if(FALSE === $file || !is_file($file)) {
            throw new RemoteStorageException("not_found", "file not found");
        }
        if(function_exists("xattr_get")) {
            $mimeType = xattr_get($file, 'mime_type');
        } else {
            $mimeType = "application/json";
        }
        $response->setHeader("Content-Type", $mimeType);
        /* XXX we should better lock that file here */
        $etag = getETag($file);
        $response->setHeader("ETag: " . $etag);

        $outputContents = true;
        if ($request->getRequestMethod() == 'HEAD') {
            $outputContents = false;
        }

        if (doIfMatchChecks($etag, $request, $response)) {
            $outputContents = false;
        }

        if ($outputContents)
            $response->setContent(file_get_contents($file));
    } else if ($request->headerExists("HTTP_AUTHORIZATION")) {
        // not public or public with Authorization header
        $token = $rs->verify($request->getHeader("HTTP_AUTHORIZATION"));

        // handle API
        switch($request->getRequestMethod()) {
            case "GET":
            case "HEAD":
                $ro = $request->getResourceOwner();
                if($ro !== $token['resource_owner_id']) {
                    throw new RemoteStorageException("access_denied", "storage path belongs to other user");
                }

                requireScope($request->getCategory(), "r", $token['scope']);

                if($request->isDirectoryRequest()) {
                    // return directory listing
                    $dir = realpath($rootDirectory . $request->getPathInfo());
                    $entries = array();
                    if(FALSE !== $dir && is_dir($dir)) {
                        $cwd = getcwd();
                        chdir($dir);
                        foreach(glob("*", GLOB_MARK) as $e) {
                            //$entries[basename($e)] = filemtime($e);
                            $entries[$e] = filemtime($e);
                        }
                        chdir($cwd);
                    }
                    if ($request->getRequestMethod() != 'HEAD')
                        $response->setContent(json_encode($entries, JSON_FORCE_OBJECT));
                } else { 
                    // accessing file, return file if it exists...
                    $file = realpath($rootDirectory . $request->getPathInfo());
                    if(FALSE === $file || !is_file($file)) {
                        throw new RemoteStorageException("not_found", "file not found");
                    }
                    if(function_exists("xattr_get")) {
                        $mimeType = xattr_get($file, 'mime_type');
                    } else {
                        $mimeType = "application/json";
                    }
                    $response->setHeader("Content-Type", $mimeType);
                   
                    $etag = getETag($file);
                    /* XXX we should better lock that file here */
                    $response->setHeader("ETag: " . $etag);
                    
                    if (doIfMatchChecks($etag, $request, $response))
                        break;

                    if ($request->getRequestMethod() != 'HEAD')
                        $response->setContent(file_get_contents($file));
                }
                break;
    
            case "PUT":
                $ro = $request->getResourceOwner();
                if($ro !== $token['resource_owner_id']) {
                    throw new RemoteStorageException("access_denied", "storage path belongs to other user");
                }

                $userDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $ro;
                // FIXME: only create when it does not already exists...
                createDirectories(array($rootDirectory, $userDirectory));

                requireScope($request->getCategory(), "rw", $token['scope']);

                if($request->isDirectoryRequest()) {
                    throw new RemoteStorageException("invalid_request", "cannot store a directory");
                } 

                // upload a file
                $file = $rootDirectory . $request->getPathInfo();
                $dir = dirname($file);
                if(FALSE === realpath($dir)) {
                    createDirectories(array($dir));
                }

                /* XXX we should better lock that file here */
                $etag = file_exists($file) ? getETag($file) : NULL;
                if (doIfMatchChecks($etag, $request, $response)) {
                    break;
                }
                
                $contentType = $request->headerExists("Content-Type") ? $request->getHeader("Content-Type") : "application/json";
                file_put_contents($file, $request->getContent());
                // store mime_type
                if(function_exists("xattr_set")) {
                    xattr_set($file, 'mime_type', $contentType);
                }

                break;

            case "DELETE":
                $ro = $request->getResourceOwner();
                if($ro !== $token['resource_owner_id']) {
                    throw new RemoteStorageException("access_denied", "storage path belongs to other user");
                }

                $userDirectory = $rootDirectory . DIRECTORY_SEPARATOR . $ro;

                requireScope($request->getCategory(), "rw", $token['scope']);

                if($request->isDirectoryRequest()) {
                    throw new RemoteStorageException("invalid_request", "directories cannot be deleted");
                }

                $file = $rootDirectory . $request->getPathInfo();            
                if(!file_exists($file)) {
                    throw new RemoteStorageException("not_found", "file not found");
                }
                if(!is_file($file)) {
                    throw new RemoteStorageException("invalid_request", "object is not a file");
                }
                
                /* XXX we should better lock that file here */
                $etag = file_exists($file) ? getETag($file) : NULL;
                if (doIfMatchChecks($etag, $request, $response)) {
                    break;
                }
                
                if (@unlink($file) === FALSE) {
                    throw new Exception("unable to delete file");
                }
                break;
            default:
                // ...
                break;

        }

    } else {
        $response->setStatusCode(401);
        $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server"'));
        $response->setContent(json_encode(array("error"=> "not_authorized", "error_description" => "need authorization to access this service"), JSON_FORCE_OBJECT));
        $logger = new Logger($config->getValue('logDirectory') . DIRECTORY_SEPARATOR . "remoteStorage.log");
        $logger->logFatal("not_authorized: need authorization to access this service");
    }   
} catch (Exception $e) {
    $config = new Config(dirname(__DIR__) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "remoteStorage.ini");
    $logger = new Logger($config->getValue('logDirectory') . DIRECTORY_SEPARATOR . "remoteStorage.log");
    switch(get_class($e)) {
        case "VerifyException":
            $response->setStatusCode($e->getResponseCode());
            $response->setHeader("WWW-Authenticate", sprintf('Bearer realm="Resource Server",error="%s",error_description="%s"', $e->getMessage(), $e->getDescription()));
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription()), JSON_FORCE_OBJECT));
            $logger->logFatal($e->getLogMessage(TRUE));
            break;

        case "RemoteStorageException":
            $response->setStatusCode($e->getResponseCode());
            $response->setContent(json_encode(array("error" => $e->getMessage(), "error_description" => $e->getDescription()), JSON_FORCE_OBJECT));
            $logger->logFatal($e->getLogMessage(TRUE));
            break;

        default:
            // any other error thrown by any of the modules, assume internal server error
            $response->setStatusCode(500);
            $response->setContent(json_encode(array("error" => "internal_server_error", "error_description" => $e->getMessage()), JSON_FORCE_OBJECT));

            $msg = 'Message    : ' . $e->getMessage() . PHP_EOL;
            $msg .= 'Trace      : ' . PHP_EOL . $e->getTraceAsString() . PHP_EOL;
            $logger->logFatal($msg);
            break;
    }

}

$response->sendResponse();

function createDirectories(array $directories) { 
    foreach($directories as $d) { 
        if(!file_exists($d)) {
            if (@mkdir($d, 0775, TRUE) === FALSE) {
                throw new Exception("unable to create directory");
            }
        }
    }
}

function getETag($file) {
    $fs = stat($file);
    return sprintf('"%x-%x-%s"', $fs['ino'], $fs['size'],
                   base_convert(str_pad($fs['mtime'], 16, "0"), 10, 16));
}

/* supply NULL for $etag if file is not present */
function doIfMatchChecks($etag, $request, $response) {
    /* XXX better use an exception? */
    if ($request->headerExists("If-Match")) {
        /* XXX the client could specify multiple ETags separated by comma */
        $match = $request->getHeader("If-Match");
        if (($match === '*' && $etag !== NULL) ||
                    ($match !== '*' && $match === $etag)) {
            return FALSE;
        }
        $response->setStatusCode("412");
        return TRUE;
    } else if ($request->headerExists("If-None-Match")) {
        /* XXX the client could specify multiple ETags separated by comma */
        $match = $request->getHeader("If-None-Match");
        if (($match === '*' && $etag === NULL) ||
                ($match !== '*' && $match !== $etag)) {
            return FALSE;
        }
        $method = $request->getRequestMethod();
        if ($method === 'HEAD' || $method === 'GET') {
            $response->setStatusCode('304');
        } else {
            $response->setStatusCode('412');
        }
        return TRUE;
    } else {
        return FALSE;
    }
}

function requireScope($collection, $permission, $grantedScope) {
    if(!in_array($permission, array("r", "rw"))) {
        throw new Exception("unsupported permission requested");
    }
    $g = explode(" ", $grantedScope);

    if("r" === $permission) {
        // both "r" and "rw" are okay here
        if(!in_array($collection . ":r", $g) && !in_array($collection . ":rw", $g) && !in_array(":r", $g) && !in_array(":rw", $g)) {
            throw new VerifyException("insufficient_scope", "require read permissions for this operation [" . $collection . "," . $permission . "," . $grantedScope . "]");
        }
    } else {
        // only "rw" is okay here
        if(!in_array($collection . ":rw", $g) && !in_array(":rw", $g)) {
            throw new VerifyException("insufficient_scope", "require write permissions for this operation [" . $collection . "," . $permission . "," . $grantedScope . "]");
        }
    }
}

?>
