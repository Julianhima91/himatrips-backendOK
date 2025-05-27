<?php

namespace App\Http\Integrations\GoFlightIntegration\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class DirectIncompleteFlightRequest extends SoloRequest
{
    use AlwaysThrowOnErrors;

    /**
     * The HTTP method of the request
     */
    protected Method $method = Method::GET;

    /**
     * The endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'https://flights-search3.p.rapidapi.com/flights/search-incomplete';
    }

    public function __construct() {}

    protected function defaultHeaders(): array
    {
        return [
            'X-RapidAPI-Key' => 'eff37b01a1msh6090de6dea39514p108435jsnf7c09e43a0a5',
            'X-RapidAPI-Host' => 'flights-search3.p.rapidapi.com',
        ];
    }

    protected function defaultQuery(): array
    {
        return [
            'currency' => 'EUR',
        ];
    }
}
