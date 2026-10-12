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
 * A design template names a base template, and the storefront opens the base template with the
 * design merged into it. Form fields meant for every product therefore live on the base template,
 * and the template the shopper gets has them - so the base template's form fields are read as well,
 * or the check would report every one of them as missing.
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
     * The template's form fields, and those of its base template when it is built on one.
     *
     * A base template that cannot be read is reported in `baseTemplateError` rather than thrown:
     * the rest of the check is still worth showing, and a base template owned by the global
     * account cannot be read at all, because Printess resolves the name in the calling account.
     *
     * @throws PrintessFormFieldsException when the token is missing or the template itself cannot be read
     *
     * @return array{fields: list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}>, baseTemplate: string, baseTemplateError: string}
     */
    public function load(string $templateName): array
    {
        $result = [
            'fields' => $this->normalize($this->request('/template/formFields/list', [
                'templateName' => $templateName,
                'usePublishedVersion' => true,
            ], 'the template form fields')),
            'baseTemplate' => '',
            'baseTemplateError' => '',
        ];

        try {
            $stored = $this->baseTemplateOf($templateName);

            if ($stored === '') {
                return $result;
            }

            $result['baseTemplate'] = $this->cleanTemplateName($stored);
            $result['fields'] = $this->merge($result['fields'], $this->normalize($this->request('/template/formFields/list', [
                'templateName' => $result['baseTemplate'],
                // The published version, which is what the storefront opens, unless the stored
                // name asks for the draft.
                'usePublishedVersion' => !str_contains($stored, '~draft'),
            ], "the base template's form fields")));
        } catch (PrintessFormFieldsException $exception) {
            $result['baseTemplateError'] = $exception->getMessage();
        }

        return $result;
    }

    /**
     * The base template this template is built on, as Printess stores it, or `''` when it has none.
     *
     * @throws PrintessFormFieldsException
     */
    private function baseTemplateOf(string $templateName): string
    {
        $data = $this->request('/template/details', ['templateName' => $templateName], 'the template details');
        $template = \is_array($data['template'] ?? null) ? $data['template'] : [];

        return \is_scalar($template['baseTemplate'] ?? null) ? trim((string) $template['baseTemplate']) : '';
    }

    /**
     * The stored name carries the version and where the template lives: `~draft`, `~published`,
     * `~merged`, and `~gid` for a template of the global account. Printess strips them the same way
     * when it loads the template for the editor.
     */
    private function cleanTemplateName(string $baseTemplate): string
    {
        $name = str_replace(['~draft', '~published', '~merged'], '', $baseTemplate);

        return str_ends_with($name, '~gid') ? substr($name, 0, -4) : $name;
    }

    /**
     * The extra fields appended under every name the template does not define itself, so the
     * template the product points at keeps precedence over its base template.
     *
     * @param list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}> $fields
     * @param list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}> $extra
     *
     * @return list<array{name: string, label: string, isPriceRelevant: bool, isList: bool, dataType: string, userInterface: string, entries: list<array{key: string, label: string}>}>
     */
    private function merge(array $fields, array $extra): array
    {
        $seen = array_fill_keys(array_column($fields, 'name'), true);

        foreach ($extra as $field) {
            if (!isset($seen[$field['name']])) {
                $seen[$field['name']] = true;
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @param string                $subject what the message names when the call fails
     *
     * @throws PrintessFormFieldsException when the token is missing or Printess cannot be read
     *
     * @return array<mixed>
     */
    private function request(string $path, array $payload, string $subject): array
    {
        $serviceToken = $this->printessConfigService->getServiceToken();

        if ($serviceToken === '') {
            throw new PrintessFormFieldsException('The Printess service token is not configured.');
        }

        try {
            $response = $this->httpClient->request('POST', $this->printessConfigService->getApiUrl() . $path, [
                'auth_bearer' => $serviceToken,
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $exception) {
            throw new PrintessFormFieldsException('Could not read ' . $subject . ' from Printess: ' . $exception->getMessage(), 0, $exception);
        }

        if ($statusCode >= 300) {
            $message = \is_string($data['m'] ?? null) && $data['m'] !== '' ? $data['m'] : 'HTTP ' . $statusCode;

            throw new PrintessFormFieldsException('Could not read ' . $subject . ' from Printess: ' . $message);
        }

        return $data;
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
