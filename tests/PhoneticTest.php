<?php

declare(strict_types=1);

namespace UAMS_Search\Tests;

use PHPUnit\Framework\TestCase;
use UAMS_Search\Phonetic;

/** Pins the Double Metaphone keys of the words that sit on each rule's edge (#185), beside the examples in `TextTest`. */
final class PhoneticTest extends TestCase
{
    public function test_the_primary_and_alternate_keys_of_the_rule_edge_words_are_pinned(): void
    {
        // Each word was picked because changing one rule, number or list entry in a way the published examples miss changes its keys
        $expected = [
            'XAVIER' => ['SF', 'SFR'],
            'OWSKIZZACHYMN' => ['ASKSKMN', 'AFSKTSKM'],
            'MCCHIATIAZHCCC' => ['MKXJKK', 'MKXJKK'],
            'WICZMACZGJSIO' => ['AKSMSKS', 'FKTSMXKS'],
            'AUX' => ['A', 'A'],
            'MANGERPPFFSJX' => ['MNJRPFSK', 'MNKRPFSK'],
            'ZZOSCEJOGGIGHTEL' => ['SSJJTL', 'TSSHKTL'],
            'SANEWSKYJZEBGY' => ['SNSKHSPK', 'SNFSKHTS'],
            'GETQX' => ['KTKKS', 'KTKKS'],
            'ILLAORCHIDALLEGNILLA' => ['ALRKTLNL', 'ALRKTKN'],
            'SHOEKKKEC' => ['SKKK', 'SKKK'],
            'MMLJOSEGNEY' => ['MLJSKN', 'MLHSKN'],
            'FILLACHEMBBDGE' => ['FLXMPJ', 'FLKMPJ'],
            'CHAEVANVANUMBEROGGI' => ['XFNFNMRJ', 'XFNFNMRK'],
            'ABFFUGH' => ['APF', 'APF'],
            'MCCIEBPHV' => ['MKSPFF', 'MKXPFF'],
            'ACHEXCRXC' => ['AXKSRKS', 'AKKSRKS'],
            'DDGIVVT' => ['TJFT', 'TKFT'],
            'ACHAEAAU' => ['AK', 'AX'],
            'SCHSSDOWRGY' => ['XSTRK', 'SSTRK'],
            'SCHEMPPTTHP' => ['SKMPTP', 'SKMPTP'],
            'CCIHOLMEIER' => ['XHLMR', 'XHLMR'],
            'XXGLIGERLLBXC' => ['SKSKLJRL', 'SKSLKRLP'],
            'MCCIA' => ['MX', 'MX'],
            'OQQDGG' => ['AKTKK', 'AKTKK'],
            'YSIASCHER' => ['ASXR', 'AXSKR'],
            'CHARACHGZEL' => ['KRKKSL', 'KRKKSL'],
            'HPPARCHITDGIHDGI' => ['PRKTJJ', 'PRKTKJ'],
            'UGHJN' => ['AKN', 'AKN'],
            'CKJJJ' => ['KJ', 'K'],
            'ILJALLE' => ['ALL', 'AL'],
            'CHORJMSK' => ['KRMSK', 'KRMSK'],
            'CHYMNNTH' => ['KMN0', 'KMNT'],
            'GESHOLMD' => ['KSLMT', 'JSLMT'],
            'GELVJBBZ' => ['KLFPS', 'JLFPS'],
            'ZOCIACHWO' => ['SSK', 'SXK'],
            'HOLMOCHFD' => ['HLMKFT', 'HLMKFT'],
            'ACIAJZZZO' => ['ASSS', 'AXSS'],
            'SCHEDKYSL' => ['SKTKL', 'SKTKL'],
            'CHARISHOLZ' => ['KRSLS', 'KRSLS'],
            'GIBWITZGNGY' => ['KPTSKNK', 'JPFXKNJ'],
            'CHSIOJLDWICZ' => ['KSLTTS', 'KSLTFX'],
            'ACCEHOLMGNJC' => ['AKSHLMNJ', 'AKSHLMKN'],
            'AGNSCSIOXXVI' => ['AKNSKKSF', 'ANSKKSF'],
            'VONCHOGGICHAELZO' => ['FNKKKLS', 'FNKKXLS'],
            'ACCEGHSMIERFALLA' => ['AKSKSMRF', 'AKSKSMRF'],
            'SL' => ['SL', 'XL'],
            'CHN' => ['KN', 'KN'],
            'CHB' => ['KP', 'KP'],
            'CHH' => ['K', 'K'],
            'DGY' => ['J', 'J'],
            'GEP' => ['KP', 'JP'],
            'GEB' => ['KP', 'JP'],
            'GIN' => ['KN', 'JN'],
            'GIE' => ['K', 'J'],
            'GEI' => ['K', 'J'],
            'HJT' => ['T', 'T'],
            'IJK' => ['AK', 'AK'],
            'SNS' => ['SNS', 'XNS'],
            'AIS' => ['A', 'AS'],
            'OUX' => ['A', 'A'],
            'CHMI' => ['KM', 'KM'],
            'MCIO' => ['MS', 'MX'],
            'MCCK' => ['MKK', 'MKK'],
            'MCCE' => ['MKS', 'MKS'],
            'GEYC' => ['KK', 'JK'],
            'SZZA' => ['SS', 'XS'],
            'BUGH' => ['P', 'P'],
            'DUGH' => ['T', 'T'],
            'WRRWR' => ['RR', 'RR'],
            'VANCH' => ['FNK', 'FNK'],
            'CHVVQ' => ['KFK', 'KFK'],
            'rough' => ['RF', 'RF'],
            'JOSEV' => ['JSF', 'HSF'],
            'BAJAN' => ['PJN', 'PHN'],
            'ALLEO' => ['AL', 'A'],
            'DGSCY' => ['TKS', 'TKS'],
            'VANTH' => ['FNT', 'FNT'],
            'VONTH' => ['FNT', 'FNT'],
            'AKRRJ' => ['AKRJ', 'AKR'],
            'EAUVX' => ['AFKS', 'AFKS'],
            'MACHQI' => ['MKK', 'MKK'],
            'PSCHUY' => ['SK', 'SK'],
            'BXOUGH' => ['PKS', 'PKS'],
            'Machine' => ['MXN', 'MKN'],
            'SUCCESS' => ['SKSS', 'SKSS'],
            'CHIAUGH' => ['K', 'K'],
            'ALLEAGAS' => ['ALKS', 'AKS'],
            'OUSHEIMR' => ['ASMR', 'ASMR'],
            'ACHSSBGE' => ['AKSPJ', 'AKSPK'],
            'Wojciewski' => ['AJSSK', 'FJXFSK'],
            'LZZILLASIO' => ['LSLS', 'LTSLX'],
            'MAIER' => ['MR', 'MR'],
            'MEIER' => ['MR', 'MR'],
            'POWSKY' => ['PSK', 'PFSK'],
            'KOWSKY' => ['KSK', 'KFSK'],
            'Smith' => ['SM0', 'XMT'],
            'Josef' => ['JSF', 'HSF'],
            'Ng' => ['NK', 'NK'],
            'AGIER' => ['AJ', 'AJR'],
            'SUGARAUCH' => ['XKRK', 'SKRK'],
            'ACCEWHDGIW' => ['AKSJ', 'AKSJF'],
            'CAESARAVXTCHOUGH' => ['SSRFKSX', 'SSRFKSX'],
            'AAVSCHGYRANGER' => ['AFXKRNKR', 'AFXJRNJR'],
            'ACHUMB' => ['AXM', 'AKM'],
            'AGGISCHENSCHEDOUGHOYSLSN' => ['AJXNSKTL', 'AKSKNSKT'],
            'GHI' => ['J', 'J'],
            'CCHUMBER' => ['KMR', 'KMR'],
            'FAGNTHAMOI' => ['FNTM', 'FKNTM'],
            'SCHACHEGNESW' => ['XKKNS', 'XKKNSF'],
            'ACHEILLPBQKNEYAGN' => ['AXLPKKNK', 'AKLPKKNK'],
            'GH' => ['K', 'K'],
            'WH' => ['A', 'A'],
            'JOSE' => ['HS', 'HS'],
            'CHOREY' => ['XR', 'XR'],
            'MCACHEJH' => ['MKKJ', 'MKKJ'],
            'AGGITTCHTTH' => ['AJTK0', 'AKTKT'],
            'ACHSHZOTIONV' => ['AKXSXNF', 'AKXSXNF'],
            'ACCEGYDDGHTLL' => ['AKSJTKTL', 'AKSKTKTL'],
            'AGGIBBMACYJOSE' => ['AJPMSJS', 'AKPMSHS'],
            'ACCETCHISLETCCIBBPHH' => ['AKSXLTXP', 'AKSXLTXP'],
            'AAGGILLUGHLFSCHOOCHOR' => ['AJLFLFSK', 'AKLFLFSK'],
            'BECH' => ['PK', 'PK'],
            'SCHW' => ['X', 'XF'],
            'BCCHOR' => ['PXR', 'PXR'],
            'AGNGGGI' => ['AKNKJ', 'ANKK'],
            'AITHZZAM' => ['A0SM', 'ATTSM'],
            'GNBOUGHHOEK' => ['NPK', 'NPK'],
            'ACHLUCCEECCEEAUSAN' => ['AKLKSXSN', 'AKLKSXSN'],
            'KN' => ['N', 'N'],
            'PN' => ['N', 'N'],
            'CQ' => ['K', 'K'],
            'SW' => ['S', 'X'],
            'CHR' => ['KR', 'KR'],
            'ACG' => ['AK', 'AK'],
            'GIL' => ['KL', 'JL'],
            'OIS' => ['A', 'AS'],
            'AZW' => ['AS', 'ATS'],
            'CHEM' => ['KM', 'KM'],
            'JOGY' => ['JJ', 'AK'],
            'THOM' => ['TM', 'TM'],
            'COUGH' => ['KF', 'KF'],
            'GIUGH' => ['JF', 'KF'],
            'VANGI' => ['FNK', 'FNK'],
            'BILLO' => ['PL', 'P'],
            'BSCHW' => ['PX', 'PX'],
            'ACHUGH' => ['AX', 'AK'],
            'CTYUGH' => ['KTF', 'KTF'],
            'DANGER' => ['TNJR', 'TNKR'],
            'RANGER' => ['RNJR', 'RNKR'],
            'ACHSCI' => ['AKS', 'AKS'],
            'ZALLEOS' => ['SLS', 'SS'],
            'AGNORCHES' => ['AKNRKS', 'ANRKS'],
        ];
        foreach ($expected as $word => $keys) {
            self::assertSame($keys, Phonetic::encode((string) $word), (string) $word);
        }
    }

    public function test_a_word_without_letters_has_empty_keys(): void
    {
        foreach (['', '123', ' - ', '!?'] as $word) {
            self::assertSame(['', ''], Phonetic::encode($word), $word);
        }
    }

    public function test_key_is_the_primary_encoding(): void
    {
        self::assertSame('SM0', Phonetic::key('Smith'));
        self::assertSame('', Phonetic::key('42'));
    }

    public function test_both_keys_stop_at_eight_characters(): void
    {
        self::assertSame(['PRTLTSLP', 'PRTLTSLP'], Phonetic::encode('Pretalatasalapakavalamanalara'));
    }
}
