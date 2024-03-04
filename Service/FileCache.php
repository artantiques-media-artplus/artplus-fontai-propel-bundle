<?php
namespace Fontai\Bundle\ProxyBundle\Service;

use ScssPhp\ScssPhp\Compiler;
use Symfony\Component\Filesystem\Filesystem;


class FileCache
{
  const COMPRESS_NONE    = FALSE;
  const COMPRESS_GZIP    = 'gzip';
  const COMPRESS_DEFLATE = 'deflate';

  protected $filesystem;

  protected $basePath;
  protected $cachePath;
  protected $useSourceMaps;
  protected $aliases = [];
  protected $cache = [];
  protected $services = [
    'text/css'        => 'http://css.fontai.com',
    'text/javascript' => 'http://js.fontai.com'
  ];

  public function __construct(
    Filesystem $filesystem,
    $aliases,
    string $basePath,
    string $cachePath,
    bool $useSourceMaps = FALSE
  )
  {
    $this->filesystem    = $filesystem;
    $this->aliases       = $aliases;
    $this->basePath      = $basePath;
    $this->cachePath     = $cachePath;
    $this->useSourceMaps = $useSourceMaps;

    $filesystem->mkdir($this->cachePath, 0777);
  }

  public function aliasExists(
    string $mime,
    string $alias
  )
  {
    if (isset($this->cache[$mime]) && $this->cache[$mime][$alias])
    {
      return TRUE;
    }

    if (!isset($this->aliases[$mime]) || !isset($this->aliases[$mime][$alias]))
    {
      return FALSE;
    }

    $this->initAlias($mime, $alias, $this->aliases[$mime][$alias]);
    
    return TRUE;
  }

  protected function initAlias(
    string $mime,
    string $alias,
    $files
  )
  {
    if (!is_array($files))
    {
      $files = [$files];
    }

    $modifiedAt = []; // Last modified times

    foreach ($files as $i => $file)
    {
      $fileName = $files[$i];
      $files[$i] = realpath($this->basePath . $file);

      if (is_file($files[$i]))
      {
        $modifiedAt[] = filemtime($files[$i]);
      }
      else
      {
        throw new \Exception(sprintf('File %s not exists', $fileName));
      }
    }

    $aliasHash = sha1($mime . $alias);

    $modifiedAt = max($modifiedAt);
    $hash = sha1(implode($files) . $modifiedAt);

    if ($mime == 'text/css')
    {
      // Check modified less files
      $cachePath = sprintf('%s/%s.csscache', $this->cachePath, $aliasHash);
      
      if (file_exists($cachePath))
      {
        $modifiedAt = [$modifiedAt];
        $cache = unserialize(file_get_contents($cachePath));

        foreach ($cache as $path => $modifiedTime)
        {
          $newModifiedTime = filemtime($path);
          
          if ($newModifiedTime > $modifiedTime)
          {
            $modifiedAt[] = $newModifiedTime;
          }
        }

        $modifiedAt = max($modifiedAt);
        $hash = sha1(implode($files) . $modifiedAt);
      }
    }

    if (!isset($this->cache[$mime]))
    {
      $this->cache[$mime] = [];
    }

    $this->cache[$mime][$alias] = [
      'alias_hash'  => $aliasHash,
      'files'       => $files,
      'modified_at' => $modifiedAt,
      'hash'        => $hash
    ];

    return $this;
  }

  public function getHash(
    string $mime,
    string $alias
  )
  {
    return $this->aliasExists($mime, $alias)
    ? $this->cache[$mime][$alias]['hash']
    : NULL;
  }

  public function getModifiedAt(
    string $mime,
    string $alias
  )
  {
    return $this->aliasExists($mime, $alias)
    ? \DateTime::createFromFormat('U', $this->cache[$mime][$alias]['modified_at'])
    : NULL;
  }  

  // Check for saved cache file existence
  public function isCached(
    string $mime,
    string $alias,
    $compression = self::COMPRESS_NONE
  )
  {
    if (!$this->aliasExists($mime, $alias))
    {
      return FALSE;
    }

    $path = sprintf('%s/%s', $this->cachePath, $this->cache[$mime][$alias]['alias_hash']) . (
      $compression != self::COMPRESS_NONE
      ? ($compression == self::COMPRESS_GZIP ? '.gzip' : '.deflate')
      : NULL
    );

    return file_exists($path) && filemtime($path) == $this->cache[$mime][$alias]['modified_at'];
  }

  protected function parseScssFile(
    string $path,
    array $cache = []
  )
  {
    $compiler = new Compiler();
    $compiler->setImportPaths(dirname($path));
    $css = $compiler->compile(file_get_contents($path), $path);

    $cache = [];

    foreach ($compiler->getParsedFiles() as $file => $modifyTime)
    {
      $cache[$file] = $modifyTime;
    }

    return [
      $css,
      $cache
    ];
  }

  protected function parseLessFile(
    string $path,
    string $lessCachePath,
    array $cache = []
  )
  {
    $mapName = sha1($path) . '.map';
    $parser = new \Less_Parser([
      'compress'         => TRUE,
      'cache_dir'        => $lessCachePath,
      'sourceMap'        => $this->useSourceMaps,
      'sourceMapWriteTo' => sprintf('%s/%s', $this->cachePath, $mapName),
      'sourceMapURL'     => sprintf('/%s/%s', substr(realpath($this->cachePath), strlen(dirname(__FILE__) . '/..')), $mapName),
    ]);
    $parser->parseFile($path, '/css/');
    $css = $parser->getCss();
    
    $cache = [];

    foreach ($parser->allParsedFiles() as $file)
    {
      $cache[$file] = filemtime($file);
    }

    return [
      $css,
      $cache
    ];
  }

  // Return compressed output for given list of files
  public function getContent(
    string $mime,
    string $alias,
    $compression = self::COMPRESS_NONE
  )
  {
    if (!$this->aliasExists($mime, $alias))
    {
      return NULL;
    }

    $aliasHash = $this->cache[$mime][$alias]['alias_hash'];
    $cachePath = sprintf('%s/%s', $this->cachePath, $aliasHash);

    if ($compression == self::COMPRESS_GZIP)
    {
      $cachePath .= '.gzip';
    }
    elseif ($compression == self::COMPRESS_DEFLATE)
    {
      $cachePath .= '.deflate';
    }

    // If file result is cached, return its content
    if ($this->isCached($mime, $alias, $compression))
    {
      return file_get_contents($cachePath);
    }
    
    $content = NULL;

    if ($mime == 'text/css')
    {
      $cache = [];

      $lessCachePath = sprintf('%s/%s.lesscache', $this->cachePath, $aliasHash);
      $lessCachePathTmp = sprintf('%s.%s', $lessCachePath, round(microtime(TRUE) * 1000));

      $this->filesystem->mkdir($lessCachePathTmp, 0777);
    }

    foreach ($this->cache[$mime][$alias]['files'] as $file)
    {
      if ($mime == 'text/javascript')
      {
        $lastChar = substr($content, -1, 1);
        $content .= ($lastChar != ';' ? ';' : NULL) . file_get_contents($file);
      }
      elseif ($mime == 'text/css')
      {
        $pInfo = pathinfo($file);

        if (isset($pInfo['extension']) && $pInfo['extension'] == 'less')
        {
          list($css, $cache) = $this->parseLessFile(
            $file,
            $lessCachePathTmp,
            $cache
          );

          $content .= $css;
        }
        elseif (isset($pInfo['extension']) && $pInfo['extension'] == 'scss')
        {
          list($css, $cache) = $this->parseScssFile($file, $cache);

          $content .= $css;
        }
        else
        {
          $content .= file_get_contents($file);
        }
      }
      else
      {
        $content .= file_get_contents($file);
      }

      $content .= "\n";
    }

    if ($mime == 'text/css')
    {
      file_put_contents(sprintf('%s/%s.csscache', $this->cachePath, $aliasHash), serialize($cache));

      if (file_exists($lessCachePath))
      {
        $lessCachePathOld = sprintf('%s.old', $lessCachePathTmp);
        rename($lessCachePath, $lessCachePathOld);
      }

      rename($lessCachePathTmp, $lessCachePath);

      if (isset($lessCachePathOld))
      {
        $this->filesystem->remove($lessCachePathOld);
      }
    }

    if (isset($this->services[$mime]))
    {
      $ch = curl_init();
      curl_setopt_array($ch, [
        CURLOPT_URL            => $this->services[$mime],
        CURLOPT_RETURNTRANSFER => TRUE,
        CURLOPT_POST           => TRUE,
        CURLOPT_POSTFIELDS     => $content
      ]);
      
      if ($response = curl_exec($ch))
      {
        $content = $response;
      }
      
      curl_close($ch);
    }
    
    // Save cached files
    if ($compression == self::COMPRESS_GZIP)
    {
      file_put_contents($cachePath, $content = gzencode($content, 9, FORCE_GZIP));
    }
    elseif ($compression == self::COMPRESS_DEFLATE)
    {
      file_put_contents($cachePath, $content = gzencode($content, 9, FORCE_DEFLATE));
    }
    else
    {
      file_put_contents($cachePath, $content);
    }

    touch($cachePath, $this->cache[$mime][$alias]['modified_at']);

    return $content;
  }
}