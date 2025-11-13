# Redirector

### A clear and practical introduction to redirects and SEO value 

Imagine a standard product entry on your site:  
`https://my-site.test/products/pretty-normal-entry`

Your client asks you to move it to another category and rename it, resulting in:  
`https://my-site.test/products/awesome-entries/really-awesome-entry`

Every internal link will update automatically if you’ve used dynamic URLs, but **external links won’t**.

### Why this matters

Those external links include **other websites, bookmarked URLs saved in users’ browsers**, and, more importantly, **Google’s search index**, which still points to the old address. Users clicking those results will land on a **404 error page** and that’s bad for both experience and SEO. 

### Why 404 pages can harm SEO

A 404 (Not Found) page isn’t always bad as it’s a **normal and expected response** when a page truly doesn’t exist or has never existed. 

However, when a 404 replaces a page that **previously contained valuable content**, it creates two main problems:
- users encounter a dead end instead of relevant information;
- search engines detect lost content and remove the page from their index, leading to:
    - **loss of PageRank and link equity** from inbound links,
    - **drop in search visibility** for related pages,
    - **delayed re-indexing** of the new URL.

In such cases, using a proper redirect is the best way to preserve both user experience and SEO value. 

### The role of redirects 

A **redirect** is a small HTTP instruction your server sends to browsers and search engines, saying:  
“This page has moved. You can find it here.”

The browser and search engine crawlers, such as Googlebot, follow that direction and reach the correct page automatically. 

There are several types of redirects, the most common being **301** and **302**:
- **301 (Permanent)** tells search engines to update their index and transfer SEO value to the new URL. 
- **302 (Temporary)** keeps the old URL indexed, used for short-term moves. 

From a user’s perspective, both behave the same way — but from an SEO standpoint, **301 redirects** are essential to preserve rankings and link equity. 

### But URLs shouldn’t change, right? 
Ideally, yes, but in the real world they often do.  
Products get rebranded or discontinued, categories are reorganized, and slugs evolve.  
When that happens, having an automated redirect system is essential to maintain SEO integrity. 

### Meet Redirector

**Redirector** helps you easily manage redirects and preserve SEO value whenever URLs change.  
It automatically handles redirects whenever an entry’s slug or its position within a Structure is updated, preventing 404 errors and protecting your site’s visibility.

Redirector allows you to manage 404 errors by generating **301 permanent redirects**, but also supports **302**, **307**, and **308** redirect types for temporary or advanced use cases. 
Additionally, it lets you assign a **410 Gone** status to 404 pages that should be permanently removed from your site, ensuring they are definitively excluded from search engine indexes.

You can:
- define exact-match or RegEx-based redirect rules;
- configure redirects globally or per site;
- monitor and manage all redirects through an intuitive interface. 

Redirector automatically ensures that every moved, renamed, or deprecated entry maintains its SEO integrity, keeping your site structure clean and your rankings protected.

### AI-powered suggestions
Redirector also offers optional AI-based assistance that helps automatically identify the most relevant redirect destinations. When enabled, the system can suggest the correct URL based on your site’s content (see the Settings section for instructions on how to generate this data), making it easier to manage complex redirects or large websites with many pages.

The AI suggestions are powered by OpenAI using its `gpt-5-nano` model in conjunction with the `file_search` tool, which retrieves suggestions from the custom knowledge base stored in a vector store.

Pricing info:
- `gpt-5-nano` model ( https://platform.openai.com/docs/pricing#text-tokens )
- `file_search` tool ( https://platform.openai.com/docs/pricing#built-in-tools )

## Requirements

This plugin requires:
- **Craft CMS** 5.8.0 or later
- **PHP** 8.2 or later

## Installation

You can install this plugin from the **Plugin Store** or with **Composer**.

#### From the Plugin Store

1. Open the **Plugin Store** in your project’s Control Panel.
2. Search for **“Redirector”**.
3. Click **Install** to complete the installation.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require paxxion/craft-redirector

# tell Craft to install the plugin
./craft plugin/install redirector
```

## Usage

Once installed, the plugin will start:  
- automatically logging any website requests that result in a 404 error into the Audit section
- generating automatic redirects if an entry’s slug or its position within a Structure changes

The plugin is divided into three sections:  
- Audit
- Redirects
- Settings

## Audit

This section shows all website requests that resulted in a 404 error.  
For each website request it is possible to create a redirect clicking the plus icon at the end of the row.

The list can be exported as a CSV file. The exported file includes only the data that matches the applied filters, if any.

## Redirects

This section displays all redirects that have been created or imported manually, as well as those automatically generated by the plugin when an entry’s slug or structure position changes.

The list can be exported as a CSV file. The exported file includes only the data that matches the applied filters, if any.

The redirects can also be imported from CSV (comma or semicolon separated).

## Settings

### Audit

#### Excluded 404

These are exact match paths that will be excluded from being logged into the Audit section.

#### Excluded 404 Patterns

These are regex match paths that will be excluded from being logged into the Audit section.

### Redirects

#### OpenAI API Key

An OpenAI API key can be configured to enable AI integration within the Redirects creation page (**AI** button on **Redirect To** field).  
AI suggestions require a support knowledge base file that will be uploaded to the OpenAI servers.

To generate this file, create a **pxx-redirector.php** file inside the **config** folder.  
This file must return, for each website entry/page, the following information:
- **Site**: The site handle of the entry/page
- **Language**: The language of the entry/page
- **Title**: The title of the entry/page
- **Description**: A short description about the content of the entry/page
- **Url**: The url of the entry/page

**Example of pxx-redirector.php file**:
```
<?php

use craft\elements\Entry;

$rows = [];

$sites = Craft::$app->sites->getAllSites();
foreach ($sites as $site) {
    $entries = Entry::find()->siteId($site->id)->all();
    foreach ($entries as $entry) {
        $url = $entry->getUrl();

        if ($url) {
            $rows[] = [
                'site' => $site->handle,
                'language' => $site->language,
                'title' => $entry->title,
                'description' => $entry->subtitle ?? '',
                'url' => $url,
            ];
        }
    }
}

return $rows;
```
The file must return an array of pages, where each element includes the keys: `site`, `language`, `title`, `description`, and `url`.  
Any entries or pages that should not be suggested by the AI must be excluded from this result set.

### UI elements

#### Url History UI Element

An UI element named **Url History UI element** can be added to the **Field Layout** of a **Entry Type**.  
It provides a record of all URL changes associated with an entry.

## Support

For questions, issues, or feature requests, please visit the [project repository on GitHub](https://github.com/paxxion/craft-redirector) and open a new **issue**.