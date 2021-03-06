<?php

require_once 'lib/Config.php';
require_once 'lib/Http/Uri.php';
require_once 'lib/Storage/RemoteStorage.php';
require_once 'lib/Storage/RemoteStorageRequest.php';
require_once 'lib/Http/HttpResponse.php';

class RemoteStorageTest extends PHPUnit_Framework_TestCase {

    private $_tmpDir;
    private $_c;

    public function setUp() {
        $this->_tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "remoteStorage_" . rand();
        mkdir($this->_tmpDir);

        // load default config
        $this->_c = new Config(dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR . "config" . DIRECTORY_SEPARATOR . "remoteStorage.ini.defaults");

        // override DB config in memory only
        $this->_c->setValue("filesDirectory", $this->_tmpDir);
    }

    public function tearDown() {
        $this->_rrmdir($this->_tmpDir);
    }

    public function testDirList() {
        $h = new RemoteStorageRequest("https://www.example.org/api.php");
        $h->setPathInfo("/dir/list/");
        $r = new RemoteStorage($this->_c, $h);
        $response = $r->getDir($h->getPathInfo());
        $this->assertEquals("{}", $response->getContent());
    }

    public function testFlow() {

        // add file
        $h = new RemoteStorageRequest("https://www.example.org/api.php", "PUT");
        $h->setPathInfo("/dir/list/foo");
        $h->setContent("Hello World");
        $r = new RemoteStorage($this->_c, $h);
        $response = $r->putFile($h->getPathInfo());
        $this->assertEquals(200, $response->getStatusCode());

        // get dir listing
        $h = new RemoteStorageRequest("https://www.example.org/api.php");
        $h->setPathInfo("/dir/list/");
        $r = new RemoteStorage($this->_c, $h);
        $response = $r->getDir($h->getPathInfo());
        $this->assertRegExp('{"foo":[0-9]+}', $response->getContent()); // FIXME: regexp is not correct, should be greedy or something!

        // get file
        $h = new RemoteStorageRequest("https://www.example.org/api.php", "GET");
        $h->setPathInfo("/dir/list/foo");
        $r = new RemoteStorage($this->_c, $h);
        $response = $r->getFile($h->getPathInfo());
        $this->assertEquals("Hello World", $response->getContent());

        // delete file
        $h = new RemoteStorageRequest("https://www.example.org/api.php", "DELETE");
        $h->setPathInfo("/dir/list/foo");
        $r = new RemoteStorage($this->_c, $h);
        $response = $r->deleteFile($h->getPathInfo());
        $this->assertEquals(200, $response->getStatusCode());
    }

    private function _rrmdir($dir) {
        foreach(glob($dir . '/*') as $file) {
            if(is_dir($file)) {
                $this->_rrmdir($file);
            } else {
                unlink($file);
            }
        }
        rmdir($dir);
    }

}
?>
