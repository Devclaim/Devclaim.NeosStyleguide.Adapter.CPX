<?php

declare(strict_types=1);

namespace Devclaim\NeosStyleguide\Adapter\Cpx\Controller;

use Devclaim\NeosStyleguide\Adapter\Cpx\Adapter\Protocol;
use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Catalogue;
use Devclaim\NeosStyleguide\Adapter\Cpx\Catalogue\Entry;
use Devclaim\NeosStyleguide\Adapter\Cpx\Rendering\ComponentFactory;
use Devclaim\NeosStyleguide\Adapter\Cpx\Rendering\NodePreview;
use Devclaim\NeosStyleguide\Adapter\Cpx\Rendering\PreviewPage;
use Devclaim\NeosStyleguide\Application\Sites;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Security\Context as SecurityContext;

/**
 * The adapter of the styleguide protocol (Devclaim.NeosStyleguide PROTOCOL.md) for CPX components, inside Neos: the
 * API under /neos/styleguide/api/v1, the preview under /neos/styleguide/preview and the node previews under
 * /neos/styleguide/node. For logged-in editors only (the
 * Neos backend session, Settings.yaml): 401 without one, the sites come from the editor's workspace.
 */
class AdapterController extends ActionController
{
    /** at most this many entries in one gallery */
    private const MAX_ENTRIES = 100;

    /**
     * @var list<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/html'];

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected Catalogue $catalogue;

    #[Flow\Inject]
    protected Protocol $protocol;

    #[Flow\Inject]
    protected ComponentFactory $componentFactory;

    #[Flow\Inject]
    protected PreviewPage $previewPage;

    #[Flow\Inject]
    protected NodePreview $nodePreview;

    #[Flow\Inject]
    protected Sites $sites;

    protected function initializeAction(): void
    {
        $this->response->setHttpHeader('Cache-Control', 'private, no-store');
        if (!$this->securityContext->hasRole('Neos.Neos:AbstractEditor')) {
            $this->response->setContentType('application/json');
            $this->throwStatus(401, null, json_encode(['error' => 'Not logged in'], JSON_THROW_ON_ERROR));
        }
    }

    public function manifestAction(): string
    {
        $previewUri = $this->uriBuilder->reset()->setCreateAbsoluteUri(false)->uriFor('preview');
        $nodePreviewUri = $this->uriBuilder->reset()->setCreateAbsoluteUri(false)->uriFor('node');

        return $this->json($this->protocol->manifest(
            strtok($previewUri, '?') ?: $previewUri,
            strtok($nodePreviewUri, '?') ?: $nodePreviewUri
        ));
    }

    public function entriesAction(): string
    {
        return $this->json(['protocol' => Protocol::VERSION, 'entries' => $this->protocol->entries()]);
    }

    public function entryAction(string $id): string
    {
        $entry = $this->catalogue->find($id);
        if ($entry === null) {
            $this->response->setContentType('application/json');
            $this->throwStatus(404, null, json_encode(['error' => 'No such entry'], JSON_THROW_ON_ERROR));
        }

        return $this->json(['protocol' => Protocol::VERSION, 'entry' => $this->protocol->details($entry)]);
    }

    public function tokensAction(): string
    {
        return $this->json($this->protocol->tokens());
    }

    /**
     * {"overrides": {"--name": "value"}}: which overrides are allowed. Reads nothing and changes nothing, so it needs
     * no CSRF token (the interface sends none).
     */
    #[Flow\SkipCsrfProtection]
    public function validateAction(): string
    {
        $body = json_decode((string)$this->request->getHttpRequest()->getBody(), true);
        $overrides = is_array($body) ? $body['overrides'] ?? null : null;
        if (!is_array($overrides) || ($overrides !== [] && array_is_list($overrides))) {
            $this->response->setContentType('application/json');
            $this->throwStatus(400, null, json_encode(['error' => 'overrides must be an object'], JSON_THROW_ON_ERROR));
        }

        return $this->json($this->protocol->validate($overrides));
    }

    /**
     * One entry (entry, useCase, propSet, props: the props changed in the inspector as JSON), or the gallery of several
     * with their defaults (entries: ids separated by commas); in the theme of the site
     */
    public function previewAction(
        ?string $entry = null,
        ?string $entries = null,
        ?string $site = null,
        ?string $useCase = null,
        ?string $propSet = null,
        ?string $props = null,
    ): string {
        $gallery = $entries !== null;
        $found = array_values(array_filter(array_map(
            $this->catalogue->find(...),
            array_slice($gallery ? explode(',', $entries) : [(string)$entry], 0, self::MAX_ENTRIES)
        )));
        if ($found === []) {
            $this->throwStatus(404, null, 'No such entry');
        }
        $changed = json_decode($props ?: '{}', true);
        $changed = is_array($changed) && !array_is_list($changed) ? $changed : [];
        $items = array_map(fn (Entry $item) => [
            'entry' => $item,
            'props' => $gallery
                ? $this->componentFactory->propsOf($item, null, null, [])
                : $this->componentFactory->propsOf($item, $propSet ?: null, $useCase ?: null, $changed),
        ], $found);
        $selected = $this->sites->pick($site);

        $this->response->setContentType('text/html');

        return $this->previewPage->render(
            title: $gallery ? 'Styleguide' : $found[0]->title,
            items: $items,
            designTokens: $selected['designTokens'] ?? null,
            language: ($selected['languages'][0]['code'] ?? '') ?: 'de',
        );
    }

    /**
     * A node preview (PROTOCOL.md, "Node previews"): the node of the reference as its page renders it, in the theme of
     * the site; a note when there is none to show (the resolver of the reference decides who may see it)
     */
    public function nodeAction(string $ref = '', ?string $site = null): string
    {
        $selected = $this->sites->pick($site);
        $this->response->setContentType('text/html');

        return $this->previewPage->renderNode(
            html: $ref === '' ? null : $this->nodePreview->render($ref, $this->request),
            designTokens: $selected['designTokens'] ?? null,
            language: ($selected['languages'][0]['code'] ?? '') ?: 'de',
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): string
    {
        $this->response->setContentType('application/json');

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
