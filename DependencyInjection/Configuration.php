<?php
namespace Fontai\Bundle\ProxyBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;


class Configuration implements ConfigurationInterface
{
  public function getConfigTreeBuilder()
  {
    $treeBuilder = new TreeBuilder('proxy');

    $treeBuilder
    ->getRootNode()
      ->children()
        ->booleanNode('source_maps')->defaultFalse()->end()
        ->arrayNode('aliases')
          ->children()
            ->variableNode('text/css')->end()
            ->variableNode('text/javascript')->end()
          ->end()
        ->end()
      ->end()
    ->end();

    return $treeBuilder;
  }
}