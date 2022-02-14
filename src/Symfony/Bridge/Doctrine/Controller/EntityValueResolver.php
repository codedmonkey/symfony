<?php

namespace Symfony\Bridge\Doctrine\Controller;

use Doctrine\DBAL\Types\ConversionException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bridge\Doctrine\Attribute\Entity;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\ExpressionLanguage\SyntaxError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\DateTimeParam;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Convert entities from request attribute variable.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Tim Goudriaan <tim@codedmonkey.com>
 */
final class EntityValueResolver implements ArgumentValueResolverInterface
{
    /**
     * @var ManagerRegistry
     */
    private $registry;

    /**
     * @var ExpressionLanguage
     */
    private $language;

    /**
     * @var array
     */
    private $defaultOptions;

    public function __construct(ManagerRegistry $registry = null, ExpressionLanguage $expressionLanguage = null)
    {
        $this->registry = $registry;
        $this->language = $expressionLanguage;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        if ($argument->getAttributes(Entity::class, ArgumentMetadata::IS_INSTANCEOF)) {
            return true;
        }

        if (null === $argument->getType()) {
            return false;
        }

        // Doctrine Entity?
        $em = $this->getManager(null, $argument->getType());
        if (null === $em) {
            return false;
        }

        return !$em->getMetadataFactory()->isTransient($argument->getType());
    }

    /**
     * {@inheritdoc}
     *
     * @throws \LogicException       When unable to guess how to get a Doctrine instance from the request information
     * @throws NotFoundHttpException When object not found
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        $name = $argument->getName();
        $class = $argument->getType();

        if ($attributes = $argument->getAttributes(DateTimeParam::class, ArgumentMetadata::IS_INSTANCEOF)) {
            $attribute = $attributes[0];
        } else {
            $attribute = new Entity();
        }

        $errorMessage = null;
        if ($expr = $attribute->getExpr()) {
            $object = $this->findViaExpression($class, $request, $expr, $attribute);

            if (null === $object) {
                $errorMessage = sprintf('The expression "%s" returned null', $expr);
            }

            // find by identifier?
        } elseif (false === $object = $this->find($class, $request, $attribute, $name)) {
            // find by criteria
            if (false === $object = $this->findOneBy($class, $request, $attribute)) {
                if ($argument->isNullable()) {
                    $object = null;
                } else {
                    throw new \LogicException(sprintf('Unable to guess how to get a Doctrine instance from the request information for parameter "%s".', $name));
                }
            }
        }

        if (null === $object && false === $argument->isNullable()) {
            $message = sprintf('%s entity not found for the "%s" parameter.', $class, $argument->getName());
            if ($errorMessage) {
                $message .= ' '.$errorMessage;
            }
            throw new NotFoundHttpException($message);
        }

        yield $object;
    }

    private function find(string $class, Request $request, Entity $attribute, string $name)
    {
        if ($attribute->getMapping() || $attribute->getExclude()) {
            return false;
        }

        $id = $this->getIdentifier($request, $attribute, $name);

        if (false === $id || null === $id) {
            return false;
        }

        $om = $this->getManager($attribute->getEntityManager(), $class);
        if ($attribute->getEvictCache() && $om instanceof EntityManagerInterface) {
            $cacheProvider = $om->getCache();
            if ($cacheProvider && $cacheProvider->containsEntity($class, $id)) {
                $cacheProvider->evictEntity($class, $id);
            }
        }

        try {
            return $om->getRepository($class)->find($id);
        } catch (NoResultException $e) {
            return;
        } catch (ConversionException $e) {
            return;
        }
    }

    private function getIdentifier(Request $request, Entity $attribute, string $name)
    {
        if (null !== $attribute->getId()) {
            if (!\is_array($attribute->getId())) {
                $name = $attribute->getId();
            } elseif (\is_array($attribute->getId())) {
                $id = [];
                foreach ($attribute->getId() as $field) {
                    if (false !== str_contains($field, '%s')) {
                        // Convert "%s_uuid" to "foobar_uuid"
                        $field = sprintf($field, $name);
                    }
                    $id[$field] = $request->attributes->get($field);
                }

                return $id;
            }
        }

        if ($request->attributes->has($name)) {
            return $request->attributes->get($name);
        }

        if ($request->attributes->has('id') && !$attribute->getId()) {
            return $request->attributes->get('id');
        }

        return false;
    }

    private function findOneBy($class, Request $request, Entity $attribute)
    {
        if (!$mapping = $attribute->getMapping()) {
            $keys = $request->attributes->keys();
            $mapping = $keys ? array_combine($keys, $keys) : [];
        }

        foreach ($attribute->getExclude() as $exclude) {
            unset($mapping[$exclude]);
        }

        if (!$mapping) {
            return false;
        }

        // if a specific id has been defined in the options and there is no corresponding attribute
        // return false in order to avoid a fallback to the id which might be of another object
        if ($attribute->getId() && null === $request->attributes->get($attribute->getId())) {
            return false;
        }

        $criteria = [];
        $em = $this->getManager($attribute->getEntityManager(), $class);
        $metadata = $em->getClassMetadata($class);

        foreach ($attribute->getMapping() as $controllerAttribute => $field) {
            if ($metadata->hasField($field)
                || ($metadata->hasAssociation($field) && $metadata->isSingleValuedAssociation($field))) {
                $criteria[$field] = $request->attributes->get($controllerAttribute);
            }
        }

        if ($attribute->getStripNull()) {
            $criteria = array_filter($criteria, function ($value) {
                return null !== $value;
            });
        }

        if (!$criteria) {
            return false;
        }

        try {
            return $em->getRepository($class)->findOneBy($criteria);
        } catch (NoResultException $e) {
            return;
        } catch (ConversionException $e) {
            return;
        }
    }

    private function findViaExpression($class, Request $request, $expression, Entity $attribute)
    {
        if (null === $this->language) {
            throw new \LogicException(sprintf('To use the @%s attribute with the "expr" option, you need to install the ExpressionLanguage component.', \get_class($attribute)));
        }

        $repository = $this->getManager($attribute->getEntityManager(), $class)->getRepository($class);
        $variables = array_merge($request->attributes->all(), ['repository' => $repository]);

        try {
            return $this->language->evaluate($expression, $variables);
        } catch (NoResultException $e) {
            return;
        } catch (ConversionException $e) {
            return;
        } catch (SyntaxError $e) {
            throw new \LogicException(sprintf('Error parsing expression -- "%s" -- (%s).', $expression, $e->getMessage()), 0, $e);
        }
    }

    private function getManager(?string $name, string $class): ?ObjectManager
    {
        if (null === $name) {
            return $this->registry->getManagerForClass($class);
        }

        return $this->registry->getManager($name);
    }
}
