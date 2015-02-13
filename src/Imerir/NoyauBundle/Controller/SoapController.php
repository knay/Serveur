<?php

namespace Imerir\NoyauBundle\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class SoapController extends Controller
{
    public function indexAction()
    {
    	$server = new \SoapServer('bundles/imerirnoyau/soap/alba.wsdl');
    	$server->setObject($this->get('soap_service'));
    	
    	$response = new Response();
    	$response->headers->set('Content-Type', 'text/xml; charset=ISO-8859-1');
    	
    	ob_start();
    	$server->handle();
    	$response->setContent(ob_get_clean());
    	
    	return $response;
    }
}
