<?php

namespace App\Domain\Expedition;

/**
 * Technologia drzewka (Epic G). Katalog zawiera tylko technologie, które
 * działają; efekt każdej liczy profil kapitana tam, gdzie zmienia regułę.
 * Działa wyłącznie po stronie gracza — lustrzany skład wroga jej nie dostaje.
 * Technologie ze zdobyczy (#121) są poza poziomami drzewka: odblokowuje je
 * tylko zbadanie konstrukcji zdobytego statku (TechUnlock::Boarding), a legendę
 * tylko ekspedycja w głąb dzikiej wyspy (TechUnlock::Quest, #144).
 */
enum Technology: string
{
    /** Magazyn złoża mieści 20 tików produkcji zamiast 10. */
    case Spichlerze = 'spichlerze';

    /** Szansa sztormu przy pierwszym wpłynięciu: połowa szansy akwenu (Morze Rozbitków 10% zamiast 20%, Ocean Sztormów 20% zamiast 40%, Morze Mgieł 5% zamiast 10%). */
    case MapySztormowe = 'mapy-sztormowe';

    /** Promień sonaru gracza w bitwach wyprawy: 4 zamiast 3. */
    case SonarDalekiegoZasiegu = 'sonar-dalekiego-zasiegu';

    /** Rejs na sektor odległy o 2 kosztuje 1 tik, jak rejs na sąsiedni. */
    case ZeglugaDwusektorowa = 'zegluga-dwusektorowa';

    /** Ładownia każdego statku +50%, z zaokrągleniem w dół. */
    case PojemneLadownie = 'pojemne-ladownie';

    /** Remont za połowę ceny, z zaokrągleniem w górę. */
    case DokRemontowy = 'dok-remontowy';

    /** Złoże daje +1 jednostkę swojego surowca na tik, zanim mnożnik akwenu go pomnoży, a tartak i kopalnia podwoją urobek. */
    case LepszeNarzedzia = 'lepsze-narzedzia';

    /** Stocznia buduje niszczyciel w wariancie zwiadowczym: 2 sonary zamiast torpedy (ShipVariant). */
    case NiszczycielZwiadowczy = 'niszczyciel-zwiadowczy';

    /** Otwiera bramę oceaniczną między Oceanem Sztormów a Morzem Mgieł, w obie strony (#108). */
    case NawigacjaOceaniczna = 'nawigacja-oceaniczna';

    /** Stocznia buduje kuter torpedowy: torpeda zamiast sonaru. Tylko z abordażu (#121). */
    case KuterTorpedowy = 'kuter-torpedowy';

    /** Stocznia buduje ciężki niszczyciel: 2 torpedy. Tylko z abordażu (#121). */
    case CiezkiNiszczyciel = 'ciezki-niszczyciel';

    /** Stocznia buduje lotniskowiec eskortowy: nalot i sonar. Tylko z abordażu (#121). */
    case LotniskowiecEskortowy = 'lotniskowiec-eskortowy';

    /** Stocznia buduje kuter remontowy: warsztat zamiast sonaru, naprawy floty poza stocznią (#152). */
    case KuterRemontowy = 'kuter-remontowy';

    /** Legenda tylko z questu (#144): +1 do widoczności na wszystkich akwenach. */
    case CzarnaPerla = 'czarna-perla';

    /** Planowanie trasy po odkrytych sektorach i przez otwarte bramy: seria zwykłych rejsów za te same tiki (#153). */
    case Nawigator = 'nawigator';

    /** Na starcie oblężenia jedno działo fortu jest namierzone na planszy wroga (nietrafione), wybrane z seeda fortu (#177). */
    case LunetyArtyleryjskie = 'lunety-artyleryjskie';

    /** Wybuch magazynu prochu w oblężeniu obejmuje 5×5 zamiast 3×3, przycięte do planszy (#177). */
    case LontyProchowe = 'lonty-prochowe';

    public function branch(): TechBranch
    {
        return match ($this) {
            self::Spichlerze, self::LepszeNarzedzia => TechBranch::Osady,
            self::PojemneLadownie, self::DokRemontowy, self::KuterRemontowy => TechBranch::Kadluby,
            self::MapySztormowe, self::ZeglugaDwusektorowa, self::NawigacjaOceaniczna, self::CzarnaPerla, self::Nawigator => TechBranch::Nawigacja,
            self::SonarDalekiegoZasiegu, self::NiszczycielZwiadowczy,
            self::KuterTorpedowy, self::CiezkiNiszczyciel, self::LotniskowiecEskortowy => TechBranch::Uzbrojenie,
            self::LunetyArtyleryjskie, self::LontyProchowe => TechBranch::Obleznictwo,
        };
    }

    /** Poziom drzewka; null = technologia spoza drzewka: ze zdobyczy albo z questu. */
    public function level(): ?TechLevel
    {
        return match ($this) {
            self::Spichlerze, self::MapySztormowe, self::SonarDalekiegoZasiegu, self::PojemneLadownie, self::LunetyArtyleryjskie => TechLevel::First,
            self::ZeglugaDwusektorowa, self::LepszeNarzedzia, self::DokRemontowy, self::NiszczycielZwiadowczy, self::Nawigator, self::LontyProchowe => TechLevel::Second,
            self::NawigacjaOceaniczna, self::KuterRemontowy => TechLevel::Third,
            self::KuterTorpedowy, self::CiezkiNiszczyciel, self::LotniskowiecEskortowy, self::CzarnaPerla => null,
        };
    }

    /**
     * Ścieżka odblokowania: badanie na uczelni, zbadanie konstrukcji zdobytego
     * statku (#121) albo legenda z ekspedycji (#144).
     */
    public function unlock(): TechUnlock
    {
        return match (true) {
            self::CzarnaPerla === $this => TechUnlock::Quest,
            null === $this->level() => TechUnlock::Boarding,
            default => TechUnlock::Academy,
        };
    }

    /**
     * Czy technologia może wypaść jako artefakt z ekspedycji (#144): poziomy
     * 1–2 uczelni. Poziom 3 (klucz do Morza Mgieł, kuter remontowy) zostaje
     * na uczelni, żeby artefakt nie przeskoczył progresji. Oblężnictwo (#177)
     * też: to przewaga na twierdze, które są celem końcowym akwenu.
     */
    public function isArtifactCandidate(): bool
    {
        $level = $this->level();

        return null !== $level && $level->value <= TechLevel::Second->value && TechBranch::Obleznictwo !== $this->branch();
    }

    /** Poziom technologii z uczelni (badanie w toku, koszt, tiki); technologia ze zdobyczy i z questu go nie ma. */
    public function academyLevel(): TechLevel
    {
        return $this->level() ?? throw new \LogicException('Technology is not researched at academy');
    }
}
