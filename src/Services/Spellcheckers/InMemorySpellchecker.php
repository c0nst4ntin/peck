<?php

declare(strict_types=1);

namespace Peck\Services\Spellcheckers;

use Peck\Config;
use Peck\Contracts\Services\Spellchecker;
use Peck\ValueObjects\Misspelling;
use PhpSpellcheck\MisspellingInterface;
use PhpSpellcheck\Spellchecker\Aspell;
use Throwable;

final readonly class InMemorySpellchecker implements Spellchecker
{
    /**
     * Creates a new instance of Spellchecker.
     */
    public function __construct(
        private Config $config,
        private Aspell $aspell,
    ) {
        //
    }

    /**
     * Creates the default instance of Spellchecker.
     */
    public static function default(): self
    {
        return new self(
            Config::instance(),
            Aspell::create(),
        );
    }

    /**
     * Checks of issues in the given text.
     *
     * @return array<int, Misspelling>
     */
    public function check(string $text): array
    {
        $allMisspellings = [];

        foreach ($this->config->languages as $language) {
            $allMisspellings[$language] = [
                ...$allMisspellings[$language] ?? [],
                ...$this->filterWhitelistedWords(iterator_to_array(
                    $this->aspell->check($text, [$language])
                ))
            ];
        }

        $result = [];

        // Keep only misspellings that are in all languages.
        foreach ($allMisspellings as $language => $misspellings) {
            foreach ($misspellings as $misspelling) {
                foreach ($this->config->languages as $languageToCheck) {
                    $foundForCurrentLanguage = current(array_filter($misspellings[$languageToCheck] ?? [], function (MisspellingInterface $compare) use ($misspelling): bool {
                        return $compare->getWord() === $misspelling->getWord() && $compare->getLineNumber() === $misspelling->getLineNumber();
                    }));

                    if ($foundForCurrentLanguage === false) {
                        $alreadyExists = current(array_filter($result, function (MisspellingInterface $compare) use ($misspelling): bool {
                            return $compare->getWord() === $misspelling->getWord() && $compare->getLineNumber() === $misspelling->getLineNumber();
                        }));

                        if ($alreadyExists === false) {
                            $result[] = $misspelling;
                        }
                    }
                }
            }
        }

        return array_map(fn (MisspellingInterface $misspelling): Misspelling => new Misspelling(
            $misspelling->getWord(),
            array_slice($misspelling->getSuggestions(), 0, 4),
        ), $misspellings);
    }

    /**
     * Filters the given words against the whitelisted words stored in the configuration.
     *
     * @param  array<int, \PhpSpellcheck\MisspellingInterface>  $misspellings
     * @return array<int, \PhpSpellcheck\MisspellingInterface> $misspellings
     */
    private function filterWhitelistedWords(array $misspellings): array
    {
        return array_filter($misspellings, fn (MisspellingInterface $misspelling): bool => ! in_array(
            strtolower($misspelling->getWord()),
            $this->config->whitelistedWords
        ));
    }
}
