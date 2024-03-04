<?php
namespace Fontai\Bundle\ProxyBundle\Controller;

use Fontai\Bundle\ProxyBundle\Service\FileCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class ProxyController extends AbstractController
{
  public function index(
    string $mime,
    string $alias,
    Request $request,
    FileCache $fileCache
  )
  {
    $response = new Response();

    if (!$fileCache->aliasExists($mime, $alias))
    {
      return $response->setStatusCode(404);
    }

    // Detect client's compression mode
    $compression = FileCache::COMPRESS_NONE;
    $encoding = $request->headers->get('Accept-Encoding');

    if ($encoding)
    {
      if (strpos($encoding, 'gzip') !== FALSE)
      {
        $compression = FileCache::COMPRESS_GZIP;
      }
      elseif (strpos($encoding, 'deflate') !== FALSE)
      {
        $compression = FileCache::COMPRESS_DEFLATE;
      }
    }

    $hash = $fileCache->getHash($mime, $alias);

    // If the same version is already cached at client side, send 304 header
    if ($fileCache->isCached($mime, $alias, $compression))
    {
      $requestHash = $request->headers->get('If-None-Match');

      if ($requestHash && stripslashes($requestHash) == sprintf('"%s"', $hash))
      {
        return $response
        ->setVary('Accept-Encoding')
        ->setNotModified();
      }
    }

    $response->setEtag($hash);
    
    if ($compression)
    {
      $response->headers->set('Content-Encoding', $compression);
    }

    $response->setVary('Accept-Encoding');
    $response->headers->set('Content-Type', sprintf('%s; charset=UTF-8', $mime));
    $response->setContent($fileCache->getContent($mime, $alias, $compression));

    return $response;
  }
}
