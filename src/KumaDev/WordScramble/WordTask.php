<?php

namespace KumaDev\WordScramble;

class WordTask {

    public static function generateScrambledWord(Main $plugin): array {
        $words = $plugin->getWords();
        $word = $words[array_rand($words)];

        $scrambledWords = array_map(function ($wordPart) {
            $chars = str_split($wordPart);
            shuffle($chars);
            return implode('', $chars);
        }, explode(' ', $word));

        $scrambled = implode(' ', $scrambledWords);

        return [
            'original' => $word,
            'scrambled' => $scrambled
        ];
    }
}
