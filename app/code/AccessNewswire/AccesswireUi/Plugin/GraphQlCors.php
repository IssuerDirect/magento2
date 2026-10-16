<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\Plugin;

use AccessNewswire\AccesswireUi\Model\AllowedOrigins;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;

/**
 * CORS for accesswire-ui calling /graphql. Only registered in the graphql
 * area (etc/graphql/di.xml). Magento itself answers OPTIONS preflights with
 * a 204; this adds the headers that let the browser use the response.
 */
class GraphQlCors
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly AllowedOrigins $allowedOrigins
    ) {
    }

    public function beforeSendResponse(HttpResponse $subject): void
    {
        // The headers differ per origin, so any HTTP cache in front must
        // key on it.
        $subject->setHeader('Vary', 'Origin', false);

        $origin = (string) $this->request->getHeader('Origin');
        if ($origin === '' || !$this->allowedOrigins->isAllowed($origin)) {
            return;
        }

        $subject->setHeader('Access-Control-Allow-Origin', $origin, true);

        if ($this->request->getMethod() === 'OPTIONS') {
            $subject->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS', true);
            $requestedHeaders = (string) $this->request->getHeader('Access-Control-Request-Headers');
            if ($requestedHeaders !== '') {
                $subject->setHeader('Access-Control-Allow-Headers', $requestedHeaders, true);
            }
            $subject->setHeader('Access-Control-Max-Age', '7200', true);
        }
    }
}
