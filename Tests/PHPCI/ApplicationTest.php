<?php

namespace Tests\PHPCI;

use PHPCI\Application;
use PHPUnit_Framework_TestCase;

class ApplicationTest extends PHPUnit_Framework_TestCase
{
    public function testFindBaseUrlDefault()
    {
        $this->assertEquals("http://example.com/phpci", Application::findBaseUrl(array(), "http://example.com/phpci"));
    }

    public function testFindBaseUrlOtherHost()
    {
        $this->assertEquals(
            "http://example.net/phpci",
            Application::findBaseUrl(
                array('HTTP_HOST' => "example.net"),
                "http://example.com/phpci"
            )
        );
    }

    public function testFindBaseUrlHttps()
    {
        $this->assertEquals(
            "https://example.com/phpci",
            Application::findBaseUrl(
                array(
                    'HTTPS' => 'yes',
                ),
                "http://example.com/phpci"
            )
        );
    }

    public function testFindBaseUrlAlias()
    {
        $this->assertEquals(
            "http://example.com/plop",
            Application::findBaseUrl(
                array(
                    'SCRIPT_NAME' => '/plop/index.php'
                ),
                "http://example.com/phpci"
            )
        );
    }

    public function testFindBaseUrlBehindProxy()
    {
        $this->assertEquals(
            "http://example.net:8080/phpci",
            Application::findBaseUrl(
                array(
                    'HTTP_HOST' => "backend.example.com",
                    'HTTP_X_FORWARDED_HOST' => 'example.net:8080',
                ),
                "http://example.com/phpci"
            )
        );
    }
}
