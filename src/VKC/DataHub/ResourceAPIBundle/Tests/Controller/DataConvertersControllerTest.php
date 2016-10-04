<?php

namespace VKC\DataHub\ResourceAPIBundle\Tests\Controller;

use VKC\DataHub\OAuthBundle\Tests\OAuthTestCase;

class DataConvertersControllerTest extends OAuthTestCase
{
    public function testDataConvertersCrudAction()
    {
        list($crawler, $response, $data) = $this->apiRequest('GET', '/resources/data/converters');
        $this->assertTrue($response->isSuccessful());
        $this->assertCRUDListContent($data);
    }
}