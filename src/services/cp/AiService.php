<?php

namespace paxxion\craftredirector\services\cp;

use Craft;
use craft\base\Component;
use OpenAI;
use paxxion\craftredirector\Redirector;

class AiService extends Component
{
    public const MODEL = 'gpt-5-nano';
    public const MAX_NUM_RESULTS = 10;
    public const VECTOR_STORE_NAME = 'craft_redirector_vector_store';
    public const KNOWLEDGE_BASE_FILE_NAME = 'craft-redirector-ai-knowledge-base.md';

    public static function verifyConnection()
    {
        $plugin = Redirector::getInstance();
        $settings = $plugin->getSettings();
        $apiKey = $settings->getOpenAiApiKey();

        if ($apiKey == '') {
            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'API key is empty.'),
            ];
        }

        try
        {
            $client = OpenAI::client($apiKey);
            $response = $client->responses()->create([
                'model' => self::MODEL,
                'input' => 'Hello!',
            ]);
        
            return [
                'success' => true,
                'message' => Craft::t('pxx-redirector', 'Connection successful!'),
            ];
        }
        catch(\Throwable $ex)
        {
            Craft::error('Redirector - error OpenAI connection test: ' . $ex->getMessage());
            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'An error has occured!') . ' ' . Craft::t('pxx-redirector', 'Check logs for more details.'),
            ];
        }
    }

    public static function getSuggestions($postData)
    {
        try
        {
            $plugin = Redirector::getInstance();
            $settings = $plugin->getSettings();
            $apiKey = $settings->getOpenAiApiKey();
            
            $client = OpenAI::client($apiKey);

            $sourceUrl = $postData['oldUrl'];
            if ($sourceUrl == '') {
                return [
                    'success' => false,
                    'message' => Craft::t('pxx-redirector', 'Redirect from field is empty'),
                ];
            }
            
            $siteHandle = 'any';
            if ($postData['siteHandle']) {
                $siteHandle = $postData['siteHandle'];
            }

            // get vector store id
            $vectorStoreId = null;
            $vectorStoreIdFilePath = Craft::getAlias('@storage/pxx-redirector/vector-store-id.txt');
            if (file_exists($vectorStoreIdFilePath)) {
                $vectorStoreId = file_get_contents($vectorStoreIdFilePath);
            }

            $systemPrompt =
            "
                You are a retrieval and re-ranking assistant for a website vector store.

                You receive two inputs:
                1. `Site` – either `any` (no language restriction) or a specific site name (e.g., `italian`, `english`, etc.), which also determines the preferred language.
                2. `Source URL` – an URL path

                Your goal:
                - Infer the topic or intent of the `Source URL` by analyzing its **path**.  
                - Use the **path structure and slug tokens** as the main signal for similarity (word tokens have priority over numeric or symbols tokens).  
                - Expand those tokens into multilingual stems and synonyms (for backup semantic matching).  
                - Query the vector store and **re-rank** using the rules below.  
                - Return up to **" . AiService::MAX_NUM_RESULTS . "** URLs, best match first.  
                - If no relevant pages exist, return `[]`.  
                - Never invent or modify URLs — output only those in the store.

                ---

                ### Matching and Re-ranking Rules

                **1. Path normalization**
                - Lowercase; strip protocol, domain, trailing slashes, and query parameters.  
                - Decode URL-encoded characters and accents.  
                - Tokenize path on `/`, `-`, `_`, and `.`.  
                - Example: `/it/prodotti/montaggio-stoccaggio-e-logistica` → tokens = `[montaggio, stoccaggio, logistica]`.

                **2. Language / site preference**
                - If `Site` is specific (e.g., `\"italian\"`): prefer entries with matching `Site`.  
                - If `Site` is `\"any\"`, ignore this filter but still reward same-language matches slightly.

                **3. Path overlap scoring (primary importance)**
                | Criteria | Points |
                |-----------|---------|
                | Exact match of any path token | +10 |
                | Exact match of all tokens (subset or full path) | +15 |
                | Shared prefix or suffix in path | +8 |
                | Synonym/stem overlap of tokens | +6 |
                | Same directory depth pattern | +3 |
                | Contains Source token within last path segment | +12 |

                **Path overlap dominates all other signals.**  
                If a candidate shares a full or partial path segment with the Source, it should rank above any that do not — even if text fields are richer.

                **4. Text field (secondary) scoring**
                | Field | Exact | Substring | Synonym/stem |
                |--------|--------|------------|---------------|
                | H1 | +4 | +2 | +2 |
                | Title | +3 | +2 | +2 |
                | Description | +1 | +1 | +1 |

                **5. Language/site weighting**
                - +5 if same site/language  
                - -4 if different

                **6. Final tie-breakers**
                - Prefer shorter, simpler URLs (fewer segments).  
                - Prefer entries whose rightmost segment overlaps the Source URL.  
                - If still tied, prefer entries whose H1 contains the main path token.

                **7. Output**
                - Return plain URLs only, one per line, ordered by best match first.  
                - If nothing relevant, return `[]`.  
                - Deterministic behavior: temperature = 0, top_p = 0.
            ";

            $userPrompt =
            "
                ### Input data
                Site: `$siteHandle`
                Source URL: `$sourceUrl`
            ";
            
            $response = $client->responses()->create([
                'model' => AiService::MODEL,
                'reasoning' => [ "effort" => "low" ],
                'tools' => [
                    [
                        'type' => 'file_search',
                        'vector_store_ids' => [ $vectorStoreId ],
                        'max_num_results' => AiService::MAX_NUM_RESULTS,
                    ]
                ],                
                'parallel_tool_calls' => false,
                'tool_choice' => 'required',
                'max_tool_calls' => 1,
                'input' => [
                    [ 'role' => 'system', 'content' => $systemPrompt ],
                    [ 'role' => 'user', 'content' => $userPrompt ],
                ],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'redirect_search_results',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'urls' => [
                                    'type' => 'array',
                                    'items' => [ 'type' => 'string' ],
                                    'maxItems' => AiService::MAX_NUM_RESULTS,
                                ],
                            ],
                            'required' => [ 'urls' ],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);

            $data = json_decode($response['output_text'], true);
            $data['urls'] = array_values(array_filter($data['urls'], function($url) {
                return $url !== '';
            }));

            return [
                'success' => true,
                'data' => $data,
            ];
        }
        catch(\Throwable $ex)
        {
            Craft::error('Redirector - error get suggestions: ' . $ex->getMessage());

            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'An error has occured!') . ' ' . Craft::t('pxx-redirector', 'Check logs for more details.'),
            ];
        }
    }

    public static function generateKnowledgeBase()
    {
        $plugin = Redirector::getInstance();

        $sourceData = Craft::$app->getConfig()->getConfigFromFile(strtolower($plugin->handle));
        if (!$sourceData) {
            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'No source data from config/{handle}.php', [ 'handle' => $plugin->handle ]),
            ];
        }

        // create folder if needed
        $folderPath = Craft::getAlias('@storage/pxx-redirector');
        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0777, true);
        }
        
        // remove any old temp file
        $tempAiKBPath = Craft::getAlias('@storage/pxx-redirector/temp.md');
        if (file_exists($tempAiKBPath)) {
            unlink($tempAiKBPath);
        }

        // create new temp file
        $tempAiKBFile = fopen($tempAiKBPath, 'wb');
        $index = 1;
        $totalRows = count($sourceData);
        foreach ($sourceData as $row) {
            fwrite($tempAiKBFile, self::renderMarkdown($row, $index === $totalRows));
            $index++;
        }
        fflush($tempAiKBFile);
        fclose($tempAiKBFile);

        // remove any old knowledge base file
        $aiKBPath = Craft::getAlias('@storage/pxx-redirector/' . self::KNOWLEDGE_BASE_FILE_NAME);
        if (file_exists($aiKBPath)) {
            unlink($aiKBPath);
        }

        // rename temp file to knowledge base file
        rename($tempAiKBPath, $aiKBPath);

        $lastModified = filemtime($aiKBPath);

        return [
                'success' => true,
                'message' => Craft::t('pxx-redirector', 'Generation completed!'),
                'date' => $lastModified
                    ? date("d/m/Y H:i:s", $lastModified)
                    : '',
            ];
    }

    public static function getGeneratedKnowledgeBaseDate()
    {
        $aiKBPath = Craft::getAlias('@storage/pxx-redirector/' . self::KNOWLEDGE_BASE_FILE_NAME);
        if (!file_exists($aiKBPath)) {
            return '';
        }

        $lastModified = filemtime($aiKBPath);

        return $lastModified
            ? date("d/m/Y H:i:s", $lastModified)
            : '';
    }

    public static function uploadKnowledgeBase()
    {
        // check source data file
        $aiKBPath = Craft::getAlias('@storage/pxx-redirector/' . self::KNOWLEDGE_BASE_FILE_NAME);
        if (!file_exists($aiKBPath)) {
            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'No data available for upload.'),
            ];
        }

        try
        {
            $plugin = Redirector::getInstance();
            $settings = $plugin->getSettings();
            $apiKey = $settings->getOpenAiApiKey();

            $client = OpenAI::client($apiKey);

            // get old files and delete them
            $response = $client->files()->list();
            foreach ($response['data'] as $file) {
                if ($file['filename'] == self::KNOWLEDGE_BASE_FILE_NAME) {
                    $client->files()->delete($file['id']);
                }
            }
            
            // get old vector stores and delete them
            $response = $client->vectorStores()->list(
                parameters: [
                    'limit' => 100,
                ],
            );
            foreach ($response['data'] as $vectorStore) {
                if ($vectorStore['name'] == self::VECTOR_STORE_NAME) {
                    $client->vectorStores()->delete(
                        vectorStoreId: $vectorStore['id'],
                    );
                }
            }

            // upload new file
            $response = $client->files()->upload([
                'purpose' => 'assistants',
                'file' => fopen($aiKBPath, 'r'),
            ]);
            $newFileId = $response['id'];
            $newFileCreatedAt = $response['created_at'];
            $newFileCreatedAt = date("d/m/Y H:i:s", $newFileCreatedAt);
            
            $response = $client->files()->retrieve($newFileId);

            // create vector store
            $response = $client->vectorStores()->create([
                'name' => self::VECTOR_STORE_NAME,
            ]);
            $vectorStoreId = $response['id'];

            // create vector store file
            $response = $client->vectorStores()->files()->create(
                vectorStoreId: $vectorStoreId,
                parameters: [
                    'file_id' => $newFileId,
                ]
            );

            // save vector store id
            $vectorStoreIdFilePath = Craft::getAlias('@storage/pxx-redirector/vector-store-id.txt');
            if (file_exists($vectorStoreIdFilePath)) {
                unlink($vectorStoreIdFilePath);
            }

            $vectorStoreIdFile = fopen($vectorStoreIdFilePath, "w");
            fwrite($vectorStoreIdFile, $vectorStoreId);
            fclose($vectorStoreIdFile);

            return [
                'success' => true,
                'message' => Craft::t('pxx-redirector', 'Upload completed!'),
                'date' => $newFileCreatedAt,
            ];
        }
        catch(\Throwable $ex)
        {
            Craft::error('Redirector - error OpenAI upload knowledge base: ' . $ex->getMessage());
            return [
                'success' => false,
                'message' => Craft::t('pxx-redirector', 'An error has occured!') . ' ' . Craft::t('pxx-redirector', 'Check logs for more details.'),
            ];
        }
    }

    public static function getUploadedKnowledgeBaseDate()
    {
        try
        {
            $plugin = Redirector::getInstance();
            $settings = $plugin->getSettings();
            $apiKey = $settings->getOpenAiApiKey();

            $client = OpenAI::client($apiKey);
            $uploadedDate = '';

            // get old files and delete them
            $response = $client->files()->list();
            foreach ($response['data'] as $file) {
                if ($file['filename'] == self::KNOWLEDGE_BASE_FILE_NAME) {
                    $timestamp = $file['created_at'];
                    $uploadedDate = date("d/m/Y H:i:s", $timestamp);
                    break;
                }
            }

            return $uploadedDate;
        }
        catch(\Throwable $ex)
        {
            Craft::error('Redirector - error OpenAI retrieving knowledge base date: ' . $ex->getMessage());
            return '';
        }
    }

    private static function renderMarkdown($row, $lastRow) {
        $url = parse_url($row['url'], PHP_URL_PATH);

        $lines = [];    
        $lines[] = "## Page";
        $lines[] = "Site: " . $row['site'] . "  ";
        $lines[] = "Language: " . $row['language'] . "  ";
        if ($row['title']) {
            $lines[] = "Title: " . $row['title'] . "  ";
        }
        if ($row['description']) {
            $lines[] = "Description: " . $row['description'] . "  ";
        }
        $lines[] = "Url: " . $url . "  ";
        
        if (!$lastRow) {            
            $lines[] = "\n---\n";
        }

        return implode("\n", $lines) . (!$lastRow ? "\n" : "");
    }
}
