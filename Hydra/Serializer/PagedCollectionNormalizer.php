<?php

/*
 * This file is part of the DunglasApiBundle package.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Dunglas\ApiBundle\Hydra\Serializer;

use Dunglas\ApiBundle\Api\ResourceClassResolverInterface;
use Dunglas\ApiBundle\Api\PaginatorInterface;
use Dunglas\ApiBundle\Exception\InvalidArgumentException;
use Dunglas\ApiBundle\Exception\RuntimeException;
use Dunglas\ApiBundle\Metadata\Resource\Factory\ItemMetadataFactoryInterface;
use Dunglas\ApiBundle\Metadata\Resource\ItemMetadata;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\SerializerAwareNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Enhance the result of collection by enabling pagination.
 *
 * @author Samuel ROZE <samuel.roze@gmail.com>
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class PagedCollectionNormalizer extends SerializerAwareNormalizer implements NormalizerInterface
{
    const HYDRA_PAGED_COLLECTION = 'hydra:PagedCollection';

    /**
     * @var NormalizerInterface
     */
    private $collectionNormalizer;

    /**
     * @var ItemMetadataFactoryInterface
     */
    private $itemMetadataFactory;

    /**
     * @var ResourceClassResolverInterface
     */
    private $resourceResolver;

    /**
     * @var string
     */
    private $pageParameterName;

    public function __construct(NormalizerInterface $collectionNormalizer, ItemMetadataFactoryInterface $itemMetadataFactory, ResourceClassResolverInterface $resourceResolver, string $pageParameterName)
    {
        $this->collectionNormalizer = $collectionNormalizer;
        $this->itemMetadataFactory = $itemMetadataFactory;
        $this->resourceResolver = $resourceResolver;
        $this->pageParameterName = $pageParameterName;
    }

    /**
     * {@inheritdoc}
     */
    public function normalize($object, $format = null, array $context = [])
    {
        $data = $this->collectionNormalizer->normalize($object, $format, $context);
        if (isset($context['jsonld_sub_level']) || !$object instanceof PaginatorInterface) {
            return $data;
        }

        $resourceClass = $this->resourceResolver->getResourceClass($object, $context);
        $itemMetadata = $this->itemMetadataFactory->create($resourceClass);

        if (isset($context['collection_operation_name'])) {
            $pageParameterName = $itemMetadata->getCollectionOperationAttribute($context['collection_operation_name'], 'pagination_page_parameter', $this->pageParameterName, true);
        } else {
            $pageParameterName = $itemMetadata->getAttribute('pagination_page_parameter', $this->pageParameterName);
        }

        list($parts, $parameters) = $this->parseRequestUri($pageParameterName, $context['request_uri'] ?? '/');

        $data['@type'] = self::HYDRA_PAGED_COLLECTION;

        $currentPage = $object->getCurrentPage();
        $lastPage = $object->getLastPage();

        if (1. !== $currentPage) {
            $previousPage = $currentPage - 1.;
            $data['hydra:previousPage'] = $this->getPageUrl($pageParameterName, $parts, $parameters, $previousPage);
        }

        if ($currentPage !== $lastPage) {
            $data['hydra:nextPage'] = $this->getPageUrl($pageParameterName, $parts, $parameters, $currentPage + 1.);
        }

        $data['hydra:totalItems'] = $object->getTotalItems();
        $data['hydra:itemsPerPage'] = $object->getItemsPerPage();
        $data['hydra:firstPage'] = $this->getPageUrl($pageParameterName, $parts, $parameters, 1.);
        $data['hydra:lastPage'] = $this->getPageUrl($pageParameterName, $parts, $parameters, $lastPage);

        // Reorder the hydra:member key to the end
        $members = $data['hydra:member'];
        unset($data['hydra:member']);
        $data['hydra:member'] = $members;

        return $data;
    }

    /**
     * Parses and standardizes the request URI.
     *
     * @param string $pageParameterName
     * @param string $requestUri
     *
     * @return array
     */
    private function parseRequestUri(string $pageParameterName, string $requestUri) : array
    {
        $parts = parse_url($requestUri);
        if (false === $parts) {
            throw new InvalidArgumentException(sprintf('The request URI "%s" is malformed.', $requestUri));
        }

        $parameters = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $parameters);

            // Remove existing page parameter
            unset($parameters[$pageParameterName]);
        }

        return [$parts, $parameters];
    }

    /**
     * Gets a collection URL for the given page.
     *
     * @param string $pageParameterName
     * @param array  $parts
     * @param array  $parameters
     * @param float  $page
     *
     * @return string
     */
    private function getPageUrl(string $pageParameterName, array $parts, array $parameters, float $page) : string
    {
        if (1. !== $page) {
            $parameters[$pageParameterName] = $page;
        }

        $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
        $parts['query'] = preg_replace('/%5B[0-9]+%5D/', '%5B%5D', $query);

        $url = $parts['path'];

        if ('' !== $parts['query']) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization($data, $format = null)
    {
        return $this->collectionNormalizer->supportsNormalization($data, $format);
    }

    /**
     * {@inheritdoc}
     */
    public function setSerializer(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;

        if ($this->collectionNormalizer instanceof SerializerAwareNormalizer) {
            $this->collectionNormalizer->setSerializer($serializer);
        }
    }
}