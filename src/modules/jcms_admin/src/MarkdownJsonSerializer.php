<?php

namespace Drupal\jcms_admin;

use League\CommonMark\Block\Element;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\DocParser;
use League\CommonMark\ElementRendererInterface;
use League\CommonMark\Node\Node;
use PHPHtmlParser\Dom;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class MarkdownJsonSerializer implements NormalizerInterface
{

    private $docParser;
    private $htmlRenderer;
    private $mimeTypeGuesser;
    private $converter;
    private $depthOffset = null;
    private $iiif = '';
    private $bracketChar = 'ø';

    public function __construct(DocParser $docParser, ElementRendererInterface $htmlRenderer, MimeTypeGuesserInterface $mimeTypeGuesser, CommonMarkConverter $converter)
    {
        $this->docParser = $docParser;
        $this->htmlRenderer = $htmlRenderer;
        $this->mimeTypeGuesser = $mimeTypeGuesser;
        $this->converter = $converter;
    }

    /**
     * @param string $object
     */
    public function normalize($object, $format = null, array $context = []) : array
    {
        $this->iiif = $context['iiif'] ?? 'https://iiif.elifesciences.org/journal-cms:';
        return $this->convertChildren($this->docParser->parse($object), $context);
    }

    private function convertChildren(Element\Document $document, array $context = []) : array
    {
        $nodes = [];
        $this->resetDepthOffset();
        foreach ($document->children() as $node) {
            if ($child = $this->convertChild($node, $context)) {
                $nodes[] = $child;
            }
        }

        return $this->implementHierarchy($this->implementQuestions($nodes));
    }

    private function resetDepthOffset()
    {
        $this->depthOffset = $this->setDepthOffset(null, true);
    }

    private function setDepthOffset($depthOffset, bool $override = false)
    {
        if (is_null($this->depthOffset) || $override === true) {
            $this->depthOffset = $depthOffset;
        }
    }

    private function getDepthOffset()
    {
        return $this->depthOffset;
    }

    /**
     * @return array|null
     */
    private function convertChild(Node $node, array $context = [])
    {
        $regexes = $context['regexes'] ?? [];
        $encode = $context['encode'] ?? [];
        switch (true) {
            case $node instanceof Element\Heading:
                if ($rendered = $this->htmlRenderer->renderBlock($node)) {
                    $depthOffset = $this->getDepthOffset();
                    $heading = (int) preg_replace('/^h([1-5])$/', '$1', $rendered->getTagName());
                    if (is_null($depthOffset) || $heading === 1) {
                        $depthOffset = 1 - $heading;
                        $this->setDepthOffset($depthOffset, ($heading === 1));
                    }

                    // Only allow 2 levels of hierarchy.
                    $depth = (($heading + $depthOffset) === 1) ? 1 : 2;

                    return [
                        'type' => 'section',
                        'title' => $rendered->getContents(),
                        'depth' => $depth,
                    ];
                }
                break;
            case $node instanceof Element\HtmlBlock:
                if ($rendered = $this->htmlRenderer->renderBlock($node)) {
                    $contents = trim($rendered);
                    if (preg_match('/^(<table[^>]*>)(.*)(<\/table>)/', $contents, $matches)) {
                        if (in_array('table', $encode)) {
                            $contents = $matches[1].$this->prepareOutput($matches[2], $context, true).$matches[3];
                        }
                        return [
                            'type' => 'table',
                            'tables' => [$this->prepareOutput($contents, $context)],
                        ];
                    } elseif (preg_match('/^<figure.*<\/figure>/', $contents)) {
                        $dom = new Dom();
                        $dom->setOptions([
                            'preserveLineBreaks' => true,
                        ]);
                        $dom->load($contents);
                        /** @var \PHPHtmlParser\Dom\HtmlNode $figure */
                        $figure = $dom->find('figure')[0];
                        $uri = ltrim($figure->getAttribute('src'), '/');
                        if (strpos($uri, 'http') !== 0) {
                            $uri = 'public://'.preg_replace('~sites/default/files/~', '', $uri);
                        }
                        $filemime = $this->mimeTypeGuesser->guess($uri);
                        if (strpos($uri, 'public://') === 0) {
                            $uri = preg_replace('~^public://iiif/~', $this->iiif, $uri);
                        }
                        $basename = basename($uri);
                        if ($filemime === 'image/png') {
                            $filemime = 'image/jpeg';
                            $basename = preg_replace('/\.png$/', '.jpg', $basename);
                        }
                        switch ($filemime) {
                            case 'image/gif':
                                $ext = 'gif';
                                break;

                            case 'image/png':
                                $ext = 'png';
                                break;

                            default:
                                $ext = 'jpg';
                        }
                        $caption = null;
                        /** @var \PHPHtmlParser\Dom\Collection $captions */
                        $captions = $figure->find('figcaption');
                        if ($captions->count()) {
                            $dom = new Dom();
                            $dom->load($this->converter->convertToHtml(trim(preg_replace('~^.*<figcaption[^>]*>\s*(.*)\s*</figcaption>.*~', '$1', $contents))));
                            /** @var \PHPHtmlParser\Dom\HtmlNode $text */
                            $text = $dom->find('p')[0];
                            $caption = $this->prepareOutput($text->innerHtml(), $context);
                        }
                        return array_filter([
                            'type' => 'image',
                            'image' => [
                                'uri' => $uri,
                                'alt' => $figure->getAttribute('alt') ?? '',
                                'source' => [
                                    'mediaType' => $filemime,
                                    'uri' => $uri.'/full/full/0/default.'.$ext,
                                    'filename' => $basename,
                                ],
                                'size' => [
                                    'width' => (int) $figure->getAttribute('width'),
                                    'height' => (int) $figure->getAttribute('height'),
                                ],
                                'focalPoint' => [
                                    'x' => 50,
                                    'y' => 50,
                                ],
                            ],
                            'title' => $caption,
                            'inline' => (bool) preg_match('/align\-left/', $figure->getAttribute('class')),
                        ]);
                    }
                }
                break;
            case $node instanceof Element\Paragraph:
                if ($rendered = $this->htmlRenderer->renderBlock($node)) {
                    $contents = $rendered->getContents();
                    if (preg_match('/^<elifebutton.*<\/elifebutton>/', $contents)) {
                        $dom = new Dom();
                        $dom->load($contents);
                        /** @var \PHPHtmlParser\Dom\HtmlNode $button */
                        $button = $dom->find('elifebutton')[0];
                        $uri = ltrim($button->getAttribute('data-href'), '/');
                        $text = $button->innerHtml();
                        return [
                            'type' => 'button',
                            'text' => $text,
                            'uri' => $uri,
                        ];
                    } elseif (preg_match('/^<oembed>(?P<youtube>https:\/\/www\.youtube\.com\/watch\?v=.*)<\/oembed>/', $contents, $matches)) {
                        $id = preg_replace('/^(|.*[^a-zA-Z0-9_-])([a-zA-Z0-9_-]{11})(|[^a-zA-Z0-9_-].*)$/', '$2', $matches['youtube']);
                        // @todo - we need to store the width and height of videos on save.
                        return [
                            'type' => 'youtube',
                            'id' => $id,
                            'width' => 16,
                            'height' => 9,
                        ];
                    } else {
                        $contents = preg_replace(array_keys($regexes), array_values($regexes), $contents);
                        return [
                            'type' => 'paragraph',
                            'text' => preg_replace('~<br\s*/?>\n~', '<br />', $contents),
                        ];
                    }
                }
                break;
            case $node instanceof Element\ListBlock:
                return $this->processListBlock($node, $context);
                break;
            case $node instanceof Element\BlockQuote:
                if ($rendered = $this->htmlRenderer->renderBlock($node)) {
                    return [
                        'type' => 'quote',
                        'text' => [
                            [
                                'type' => 'paragraph',
                                'text' => trim(preg_replace('/^[\s]*<p>(.*)<\/p>[\s]*$/s', '$1', $rendered->getContents())),
                            ],
                        ],
                    ];
                }
                break;
            case $node instanceof Element\FencedCode:
            case $node instanceof Element\IndentedCode:
                if ($contents = $node->getStringContent()) {
                    if (in_array('code', $encode)) {
                        $contents = $this->prepareOutput($contents, $context, true);
                    }
                    return [
                        'type' => 'code',
                        'code' => $this->prepareOutput($contents, $context),
                    ];
                }
                break;
        }

        return null;
    }

    private function prepareOutput($content, $context = [], $decode = false) {
        $regexes = $context['regexes'] ?? [];
        $output = trim(($decode) ? base64_decode($content) : $content);
        if (!empty($regexes)) {
            $output = preg_replace(array_keys($regexes), array_values($regexes), $output);
        }
        return $output;
    }

    private function implementQuestions(array $nodes) : array
    {
        $question_found = false;
        $new_nodes = [];
        for ($i = 0; $i < count($nodes); $i++) {
            $node = $nodes[$i];
            if ($node['type'] === 'section' && preg_match('~^Question: (?P<question>.+)$~i', $node['title'], $match)) {
                $question_found = true;
                $new_nodes[] = [
                    'type' => 'question',
                    'question' => $match['question'],
                    'answer' => [],
                ];
                continue;
            }
            elseif ($node['type'] === 'paragraph') {
                if (preg_match('~^<strong>Question: (?P<question>.+)</strong>$~i', $node['text'], $match)) {
                    $question_found = true;
                    $new_nodes[] = [
                        'type' => 'question',
                        'question' => $match['question'],
                        'answer' => [],
                    ];
                    continue;
                }
                elseif ($question_found) {
                    $new_nodes[count($new_nodes)-1]['answer'][] = $node;
                    continue;
                }
            }
            $new_nodes[] = $node;
            $question_found = false;
        }
        return $new_nodes;
    }

    private function implementHierarchy(array $nodes) : array
    {
        // Organise 2 levels of section.
        for ($level = 2; $level > 0; $level--) {
            $hierarchy = [];
            for ($i = 0; $i < count($nodes); $i++) {
                $node = $nodes[$i];

                if ($node['type'] === 'section' && isset($node['depth']) && $node['depth'] === $level) {
                    unset($node['depth']);
                    for ($j = $i + 1; $j < count($nodes); $j++) {
                        $sectionNode = $nodes[$j];
                        if ($sectionNode['type'] === 'section' && isset($sectionNode['depth']) && $sectionNode['depth'] <= $level) {
                            break;
                        } else {
                            $node['content'][] = $sectionNode;
                        }
                    }
                    $i = $j - 1;
                    if (empty($node['content'])) {
                        continue;
                    }
                }
                $hierarchy[] = $node;
            }
            $nodes = $hierarchy;
        };

        return $hierarchy ?? [];
    }

    private function processListBlock(Element\ListBlock $block, $context = [])
    {
        $gather = function (Element\ListBlock $list) use (&$gather, &$render, $context) {
            $items = [];
            foreach ($list->children() as $item) {
                foreach ($item->children() as $child) {
                    if ($child instanceof Element\ListBlock) {
                        $items[] = [$render($child)];
                    } elseif ($item = $this->htmlRenderer->renderBlock($child)) {
                        $items[] = $this->prepareOutput($item->getContents(), $context);
                    }
                }
            }

            return $items;
        };

        $render = function (Element\ListBlock $list) use ($gather) {
            return [
                'type' => 'list',
                'prefix' => (Element\ListBlock::TYPE_ORDERED === $list->getListData()->type) ? 'number' : 'bullet',
                'items' => $gather($list),
            ];
        };

        return $render($block);
    }

    public function supportsNormalization($data, $format = null) : bool
    {
        return $data instanceof Element\Document;
    }

    private function preserveCode(string $html) : string
    {
        $bc = $this->bracketChar;
        $preserve = preg_replace('~<(/?code[^>]*)>~', $bc.'$1'.$bc, $html);
        return preg_replace_callback('~'.$bc.'code[^'.$bc.']*'.$bc.'([^'.$bc.']*)'.$bc.'/\1'.$bc.'~s', function ($matches) use ($bc) {
            return '<code>'.base64_encode($matches[1]).'</code>';
        }, $preserve);
    }

}