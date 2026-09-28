<?php declare(strict_types=1);

/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2026 Printess GmbH & Co. Kg
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * Printess GmbH & Co. Kg does not provide any support for this plugin.
 */

namespace PrintessShopwareIntegration\Service\TemplateCheck;

use PrintessShopwareIntegration\Service\Configuration\PrintessConfigService;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a template's form fields from Printess (`/template/formFields/list`, published version)
 * and normalizes them into the shape the template check and the variant sync compare against:
 *
 * `{name, label, isPriceRelevant, isList, dataType, userInterface, entries: [{key, label}]}`
 *
 * Deliberately not cached: "Check again" exists because the merchant has just changed the template
 * in Printess, and a cached answer would repeat the old problem.
 */
class PrintessFormFieldsClient
{
    /**
     * `userInterface` values of list controls. Matched as prefixes, so `select-list+info`,
     * `image-list+caption` and `color-list` are covered.
     */
    private const LIST_INTERFACES = ['select-list', 'tab-list', 'image-list', 'color'];

    public function __construct(
        private readonly PrintessConfigService $printessConfigService,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @throws PrintessFormFieldsException when the token is missing or Printess cannot be read
     *
     * @return list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}>
     */
    public function load(string $templateName): array
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            throw new PrintessFormFieldsException('The Printess service token is not configured.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->printessConfigService->getApiUrl() . '/template/formFields/list', [
                'auth_bearer' => $serviceToken,
                'json' => [
                    'templateName' => $templateName,
                    'usePublishedVersion' => true,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new PrintessFormFieldsException('Could not read the template form fields from Printess: ' . $exception->getMessage(), 0, $exception);
        }

        if ($statusCode >= 300) {
            $message = \is_string($data['m'] ?? null) && $data['m'] !== '' ? $data['m'] : 'HTTP ' . $statusCode;

            throw new PrintessFormFieldsException('Could not read the template form fields from Printess: ' . $message);
        }

        return $this->normalize($data);
    }

    /**
     * Global fields first, then every document's own fields. A name that appears more than once
     * (a field shared by several documents) is taken from its first occurrence.
     *
     * @param array<mixed> $data
     *
     * @return list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}>
     */
    private function normalize(array $data): array
    {
        $raw = \is_array($data['formFields'] ?? null) ? $data['formFields'] : [];

        foreach (\is_array($data['documentFormFields'] ?? null) ? $data['documentFormFields'] : [] as $document) {
            if (\is_array($document) && \is_array($document['formFields'] ?? null)) {
                array_push($raw, ...$document['formFields']);
            }
        }

        $fields = [];
        $seen = [];

        foreach ($raw as $field) {
            $normalized = \is_array($field) ? $this->normalizeField($field) : null;

            if ($normalized === null || isset($seen[$normalized['name']])) {
                continue;
            }

            $seen[$normalized['name']] = true;
            $fields[] = $normalized;
        }

        return $fields;
    }

    /**
     * @param array<mixed> $field
     *
     * @return array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}|null
     */
    private function normalizeField(array $field): ?array
    {
        $name = \is_scalar($field['name'] ?? null) ? (string) $field['name'] : '';

        if ($name === '') {
            return null;
        }

        $display = \is_scalar($field['display'] ?? null) ? (string) $field['display'] : '';
        $dataType = \is_scalar($field['dataType'] ?? null) ? (string) $field['dataType'] : '';
        $userInterface = \is_scalar($field['userInterface'] ?? null) ? (string) $field['userInterface'] : '';
        $entries = [];

        foreach (\is_array($field['entries'] ?? null) ? $field['entries'] : [] as $entry) {
            if (!\is_array($entry) || !\is_scalar($entry['key'] ?? null)) {
                continue;
            }

            $key = (string) $entry['key'];
            $label = \is_scalar($entry['label'] ?? null) && (string) $entry['label'] !== '' ? (string) $entry['label'] : $key;
            $entries[] = ['key' => $key, 'label' => $label];
        }

        return [
            'name' => $name,
            'label' => $display !== '' ? $display : $name,
            'isPriceRelevant' => !empty($field['isPriceRelevant']),
            // A colour field (`dataType: color`) is a list in the editor, but Printess does not
            // enumerate its colours: no entries means "values unknown", not "not a list".
            'isList' => $entries !== [] || $dataType === 'color' || $this->isListInterface($userInterface),
            'dataType' => $dataType,
            'userInterface' => $userInterface,
            'entries' => $entries,
        ];
    }

    private function isListInterface(string $userInterface): bool
    {
        foreach (self::LIST_INTERFACES as $prefix) {
            if (str_starts_with($userInterface, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
