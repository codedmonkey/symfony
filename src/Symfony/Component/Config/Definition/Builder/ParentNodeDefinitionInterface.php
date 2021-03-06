<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Config\Definition\Builder;

/**
 * An interface that must be implemented by nodes which can have children.
 *
 * @author Victor Berchet <victor@suumit.com>
 *
 * @method NodeDefinition[] getChildNodeDefinitions() should be implemented since 4.1
 */
interface ParentNodeDefinitionInterface extends BuilderAwareInterface
{
    /**
     * @return NodeBuilder
     */
    public function children();

    public function append(NodeDefinition $node);
}
