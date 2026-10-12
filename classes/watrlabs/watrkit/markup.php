<?php

namespace watrlabs\watrkit;

// turns forum post text into html. everything gets escaped first, then a small
// set of markdown-ish things come back: **bold** *italic* ~~strike~~ `code`,
// ``` code blocks ```, > quotes, links, bare urls and @mentions
class markup {

    private static array $held = [];

    static function render(string $text): string {
        self::$held = [];

        $text = str_replace(["\r\n", "\r"], "\n", trim($text));

        // fenced code blocks first so nothing inside them gets formatted
        $text = preg_replace_callback('/```[a-z0-9]*\n?(.*?)```/s', function ($m) {
            return "\n\n" . self::hold('<pre><code>' . self::e(trim($m[1], "\n")) . '</code></pre>') . "\n\n";
        }, $text);

        $html = [];
        foreach (preg_split('/\n{2,}/', $text) as $block) {
            $block = trim($block, "\n");
            if ($block === '') {
                continue;
            }

            if (preg_match('/^\x00\d+\x00$/', $block)) {
                $html[] = $block;
                continue;
            }

            $lines = explode("\n", $block);
            $isQuote = count(array_filter($lines, fn ($l) => str_starts_with($l, '>'))) === count($lines);

            if ($isQuote) {
                $inner = implode("\n", array_map(fn ($l) => ltrim(substr($l, 1)), $lines));
                $html[] = '<blockquote>' . nl2br(self::inline($inner), false) . '</blockquote>';
            } else {
                $html[] = '<p>' . nl2br(self::inline($block), false) . '</p>';
            }
        }

        $out = implode("\n", $html);

        // put the held bits back, twice in case one was held inside another
        for ($i = 0; $i < 2; $i++) {
            $out = preg_replace_callback('/\x00(\d+)\x00/', fn ($m) => self::$held[(int) $m[1]] ?? '', $out);
        }

        return $out;
    }

    private static function inline(string $text): string {
        // `code` gets held before escaping so its contents stay literal
        $text = preg_replace_callback('/`([^`\n]+)`/', fn ($m) => self::hold('<code>' . self::e($m[1]) . '</code>'), $text);

        // [text](https://link)
        $text = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', function ($m) {
            return self::hold(self::link($m[2], self::e($m[1])));
        }, $text);

        // bare urls
        $text = preg_replace_callback('/https?:\/\/[^\s<>"\x00]+/', function ($m) {
            $url = rtrim($m[0], '.,!?:;)\'');
            $rest = substr($m[0], strlen($url));
            return self::hold(self::link($url, self::e($url))) . $rest;
        }, $text);

        $text = self::e($text);

        $text = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/(?<![*\w])\*(?=\S)(.+?)(?<=\S)\*(?![*\w])/s', '<em>$1</em>', $text);
        $text = preg_replace('/(?<![_\w])_(?=\S)(.+?)(?<=\S)_(?![_\w])/s', '<em>$1</em>', $text);
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/s', '<del>$1</del>', $text);

        // @someone links to their profile
        $text = preg_replace('/(^|[\s(])@([A-Za-z0-9_.-]{3,20})(?<![.-])/m', '$1<a href="/u/$2" class="mention">@$2</a>', $text);

        return $text;
    }

    private static function link(string $url, string $label): string {
        return '<a href="' . self::e($url) . '" rel="nofollow ugc noopener" target="_blank">' . $label . '</a>';
    }

    private static function hold(string $html): string {
        self::$held[] = $html;
        return "\x00" . (count(self::$held) - 1) . "\x00";
    }

    private static function e(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

}
