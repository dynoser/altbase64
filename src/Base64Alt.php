<?php
namespace dynoser\base64;

class Base64Alt {
    public static $base64cs = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    public static $base64cn = []; // [char] => num64

    public static $charSet = [
        'enLow' => 'a b c d e f g h i j k l m n o p q r s t u v w x y z $ \' { } = @  ! ? . , : ; - + / * " ( )',
        'enUpc' => 'A B C D E F G H I J K L M N O P Q R S T U V W X Y Z $ \' { } = @  ^ ~ [ ] \\ | _ & % # ` < >',
        'ruLow' => 'а б с д е ф ж н и й к л м п о р я г щ т ю в щ х у з ъ ь ы э ц ч ё ! ? . , : ; - + / * " ( )',
        'ruUpc' => 'А Б С Д Е Ф Ж Н И Й К Л М П О Р Я Г Щ Т Ю В Ш Х У З Ъ Ь Ы Э Ц Ч Ё ^ ~ [ ] \\ | _ & % # ` < >',
    ];
    
    public static $keys4Arr = []; // [enLow, enUpc, ruLow, ruUpc]
    
    public static $fromChToNum64 = [];
    
    public static $allCharsRU = []; // [utfChar] => num64;
    
    public static function init() {

        foreach(self::$charSet as $currKey => $str) {
            $str .= '       0 1 2 3 4 5 6 7 8 9';
            self::$keys4Arr[] = $currKey;
            self::$fromChToNum64[$currKey] = [];
            $chArr = \explode(' ', $str);
            foreach($chArr as $n => $utfChar) {
                if ($utfChar !== '') {
                    self::$fromChToNum64[$currKey][$utfChar] = $n;
                    if (\strlen($utfChar) > 1) {
                        self::$allCharsRU[$utfChar] = $n;
                    }
                }
            }
            self::$fromChToNum64[$currKey][' '] = 62;
            self::$fromChToNum64[$currKey]["\n"] = 63;
            $chArr[62] = ' ';
            $chArr[63] = "\n";

            self::$charSet[$currKey] = $chArr;
        }
        
        for($p = 0; $p < 64; $p++) {
            self::$base64cn[self::$base64cs[$p]] = $p;
        }
        self::$base64cn['+'] = 62;
        self::$base64cn['/'] = 63;
    }
    
    public static function encodeSwitch($str) {
        $b64stdLen = 4 * \ceil(\strlen($str) / 3);
        $arr = self::encodeBytes($str);
        if (\count($arr) > $b64stdLen) {
            $enc = \base64_encode($str);
            return \rtrim(\strtr($enc, '+/', '-_'), '=');
        }
        $out = ['='];
        foreach($arr as $k => $num64) {
            $out[] = self::$base64cs[$num64];
        }
        return \implode('', $out);
    }
    
    public static function decodeSwitch($b64) {
        if (\substr($b64, 0, 1) !== '=') {
            return \base64_decode(\strtr($b64, '-_', '+/'));
        }
        self::$keys4Arr || self::init();
        $len = \strlen($b64);
        $arr = [];
        for($p = 1; $p < $len; $p++) {
            $cn = self::$base64cn[$b64[$p]] ?? -1;
            if ($cn < 0) {
                continue;
            }
            $arr[] = $cn;
        }
        return \implode('', self::decodeBytes($arr));
    }
    
    public static function decodeBytes($num64Arr) {

        $currKey = 'enLow';
        $lang = 'en';
        $mode = 'Low';
        $up1 = false;

        $maxPos = \count($num64Arr) - 1;
        $out = [];

        for($currPos = 0; $currPos <= $maxPos; $currPos++) {
            $num64 = $num64Arr[$currPos];
            switch($num64) {
                case 46: // b128+
                    if ($currPos < $maxPos) {
                        $out[] = \chr($num64Arr[++$currPos] + 128);
                    }
                    break;                    
                case 47: // b192+
                    if ($currPos < $maxPos) {
                        $out[] = \chr($num64Arr[++$currPos] + 192);
                    }
                    break;                    
                case 48: // en+
                    if ($currPos < $maxPos && $num64Arr[$currPos+1] > 31) {
                        $bNum = $num64Arr[++$currPos] - 32;
                        $out[] = \chr($bNum === 10 ? 127 : $bNum);
                        break;
                    }
                    if ($lang === 'ru') {
                        $lang = 'en';
                        $currKey = $lang . $mode;
                    }
                    break;
                case 49: // low
                    $mode = 'Low';
                    $currKey = $lang . $mode;
                    break;
                case 50: // up1
                    $mode = 'Upc';
                    $currKey = $lang . $mode;
                    $up1 = true;
                    break;
                case 51: // CAPS
                    $mode = 'Upc';
                    $currKey = $lang . $mode;
                    $up1 = false;
                    break;
                case 32: // ru (only if en)
                    if ($lang === 'en') {
                        $lang = 'ru';
                        $currKey = $lang . $mode;
                        break;
                    }
                default:
                    $out[] = self::$charSet[$currKey][$num64];
                    if ($up1) {
                        $up1 = false;
                        $mode = 'Low';
                        $currKey = $lang . $mode;
                    }
            }
        }
        return $out;
    }
    
    public static function encodeBytes($str, $brkLen = 0) {
        self::$keys4Arr || self::init();
        $out = [];
        $currKey = 'enLow';
        $len = \strlen($str);
        
        $prevUp1 = -1;
        
        for($i = 0; $i < $len; $i++){
            $utfChar = $str[$i];
            $cn = \ord($utfChar);
            if ($cn < 32) {
                if ($cn === 10) {
                    $out[] = 63; // EOL
                } else {
                    $out[] = 48; // en
                    $out[] = $cn + 32;
                }
                continue;
            } elseif ($cn > 126) {
                // utf-8 RU or bytes
                $charIsRu = false;
                if ($cn > 207 && $cn < 210 && $len > $i + 1) {
                    $utfChar = \substr($str, $i, 2);
                    if (isset(self::$allCharsRU[$utfChar])) {
                        $i++;
                        $charIsRu = true;
                    }
                }
                if (!$charIsRu) {
                    if ($cn === 127) {
                        $out[] = 48; // en
                        $out[] = 42; // 32 + 10
                    } elseif ($cn > 191) {
                        $out[] = 47; // b192+
                        $out[] = $cn - 192;
                    } else {
                        $out[] = 46; // b128+
                        $out[] = $cn - 128;
                    }
                    continue;
                }
            }
            
            if (isset(self::$fromChToNum64[$currKey][$utfChar])) {
                $out[] = self::$fromChToNum64[$currKey][$utfChar];
                $prevUp1 = -1;
                continue;
            }

            // try find in other keys
            $whereKeysArr = [];
            foreach(self::$keys4Arr as $keyNum => $key) {
                if ($key === $currKey) {
                    $currKeyNum = $keyNum;
                    continue;
                }
                if (isset(self::$fromChToNum64[$key][$utfChar])) {
                    $whereKeysArr[$keyNum] = $key;
                }
            }
            
            $c = \count($whereKeysArr);
            if ($c) {
                // key found
                if ($c === 2) {
                    if (isset($whereKeysArr[1]) && isset($whereKeysArr[3])) {
                        if ($currKeyNum & 2) {
                            // current is RU - remove 1 (en)
                            unset($whereKeysArr[1]);
                        } else {
                            // current is en - remove 3 (ru)
                            unset($whereKeysArr[3]);
                        }
                        $c = 1;
                    } elseif (isset($whereKeysArr[0]) && isset($whereKeysArr[2])) {
                        if ($currKeyNum & 2) {
                            // current is RU - remove 1 (en)
                            unset($whereKeysArr[0]);
                        } else {
                            // current is en - remove 3 (ru)
                            unset($whereKeysArr[2]);
                        }
                        $c = 1;
                    } elseif (isset($whereKeysArr[0]) && isset($whereKeysArr[1])) {
                        if ($currKeyNum & 1) {
                            // current is Upc
                            unset($whereKeysArr[0]);
                        } else {
                            // current is Low
                            unset($whereKeysArr[1]);
                        }
                        $c = 1;
                    } else {
                        throw new \Exception("Unexpected");
                    }
                }
                if ($c === 1) {
                    $newKeyNum = \key($whereKeysArr);
                    $changedNum = $newKeyNum ^ $currKeyNum;
                    
                    if ($changedNum & 1) {
                        if ($currKeyNum & 1) {
                            // current is UP-case, will changed to lowerCase
                            if ($prevUp1 < 0) {
                                $out[] = 49; // ctrl 7 low
                            } else {
                                $out[$prevUp1] = 50;
                            }
                        } else {
                            // current is lower-case, will be changed to UP
                            $prevUp1 = \count($out);
                            $out[] = 51; // ctrl 5 caps
                        }
                    }
                    
                    if ($changedNum & 2) {
                        if ($currKeyNum & 2) {
                            // current is RU, will be changed to EN
                            $out[] = 48; // ctrl 4 en
                        } else {
                            // current is EN, will be changed to RU
                            $out[] = 32; // ctrl 1 ru
                        }
                    }

                    $currKey = $whereKeysArr[$newKeyNum];
                    $out[] = self::$fromChToNum64[$currKey][$utfChar];
                } else {
                    throw new \Exception("Unexpected");
                }
                
                if ($brkLen && \count($out)>$brkLen) {
                    return null;
                }
            }
        }
        if ($brkLen && \count($out)>$brkLen) {
            return null;
        }
        return $out;
    }
}
