<?php
namespace Fontai\Bundle\ProxyBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;


class ProxyExtension extends Extension
{
  public function load(array $configs, ContainerBuilder $container)
  {
    $configuration = new Configuration();
    $config = $this->processConfiguration($configuration, $configs);

    $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
    $loader->load('proxy.yaml');

    $container->setParameter('proxy.source_maps', $config['source_maps']);
    $container->setParameter('proxy.aliases', $config['aliases']);
    $container->setParameter('proxy.public_dir', $this->getPublicDirectory($container));
  }

  protected function getPublicDirectory(ContainerBuilder $container)
  {
    $kernelProjectDir = $container->getParameter('kernel.project_dir');
    $publicDir = 'public';
    $composerFilePath = sprintf('%s/composer.json', $kernelProjectDir);

    if (file_exists($composerFilePath))
    {
      $composerConfig = json_decode(file_get_contents($composerFilePath), TRUE);

      if (isset($composerConfig['extra']['public-dir']))
      {
        $publicDir = $composerConfig['extra']['public-dir'];
      }
    }

    return sprintf(
      '%s/%s',
      $kernelProjectDir,
      $publicDir
    );
  }

  public function getAlias()
  {
    return 'proxy';
  }
}