<?php

namespace App\Http\Integrations\GoFlightIntegration\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\SoloRequest;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class OneWayDirectFlightRequest extends SoloRequest
{
    use AlwaysThrowOnErrors;

    /**
     * Define the HTTP method
     */
    protected Method $method = Method::GET;

    public function __construct() {}

    /**
     * Define the endpoint for the request
     */
    public function resolveEndpoint(): string
    {
        return 'https://sky-scanner3.p.rapidapi.com/flights/search-one-way';
    }

    protected function defaultQuery(): array
    {
        return [
            'currency' => 'EUR',
        ];
    }

    protected function defaultHeaders(): array
    {
        return [
            'X-RapidAPI-Key' => 'eff37b01a1msh6090de6dea39514p108435jsnf7c09e43a0a5',
            'X-RapidAPI-Host' => 'sky-scanner3.p.rapidapi.com',
        ];
    }
}
