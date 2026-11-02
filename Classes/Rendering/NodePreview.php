<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Rendering;

use Devclaim\NeosStyleguide\NodeReferences\NodeReferences;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use Neos\Neos\Domain\Model\RenderingMode;
use Neos\Neos\Domain\NodeMapping\NodeMapperInterface;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use PackageFactory\Neos\ComponentEngine\Infrastructure\Neos\NeosContextFactory;
use PackageFactory\Neos\ComponentEngine\Integration\ContentNodeRendererInterface;
use PackageFactory\Neos\ComponentEngine\Integration\NodeRendererProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * The node previews of the styleguide (Devclaim.NeosStyleguide PROTOCOL.md, "Node previews"): the node of a node
 * reference (e.g. a change of the activity log, before or after) rendered by its content renderer, as on its page.
 * The renderer is called directly, not through the ContentRenderer: that caches by node and would show the node as it
 * is now for every state of it.
 */
#[Flow\Scope('singleton')]
class NodePreview
{
    #[Flow\Inject]
    protected NodeReferences $nodeReferences;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected NeosContextFactory $neosContextFactory;

    #[Flow\Inject]
    protected NodeRendererProviderInterface $nodeRendererProvider;

    #[Flow\Inject]
    protected NodeMapperInterface $nodeMapper;

    #[Flow\Inject(name: 'Neos.Flow:SystemLogger')]
    protected LoggerInterface $logger;

    /**
     * The HTML of the node, null when there is none to show: the reference is unknown or not for this user, the node is
     * not on a page, or it has no content renderer of its own (a part of the page layout)
     */
    public function render(string $reference, ActionRequest $request): ?string
    {
        $node = $this->nodeReferences->resolve($reference);
        if ($node === null) {
            return null;
        }
        $subgraph = $this->contentRepositoryRegistry->subgraphForNode($node);
        $documentNode = $subgraph->findClosestNode(
            $node->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_DOCUMENT)
        );
        $siteNode = $documentNode === null ? null : $subgraph->findClosestNode(
            $documentNode->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );
        if ($documentNode === null || $siteNode === null || $documentNode->aggregateId->equals($node->aggregateId)) {
            return null;
        }

        try {
            $context = $this->neosContextFactory
                ->create($documentNode, $siteNode, $subgraph, $request, RenderingMode::createFrontend())
                ->with(current: $this->nodeMapper->map($node, $subgraph));
            $renderer = $this->nodeRendererProvider->provideNodeRenderer($context);
            if (!$renderer instanceof ContentNodeRendererInterface) {
                return null;
            }
            return $renderer->renderAsContent($context)->render();
        } catch (\Throwable $error) {
            $this->logger->warning(sprintf(
                'Styleguide: node preview %s cannot be rendered: %s',
                $reference,
                $error->getMessage()
            ));
            return null;
        }
    }
}
