<?php

namespace StripeIntegration\Payments\Model\Customer;

class NameParser
{
    private $firstName = null;
    private $middleName = null;
    private $lastName = null;

    public function fromString(?string $name)
    {
        if (!is_string($name))
            return $this;

        $name = trim($name);

        if (empty($name))
            return $this;

        $name = preg_replace('!\s+!', ' ', $name); // Replace multiple spaces

        // Chinese (and other CJK) names do not use spaces and place the surname
        // first. The space-based splitting below would treat the whole name as a
        // firstname, leaving the required lastname empty.
        if ($this->isCjkName(str_replace(' ', '', $name)))
        {
            $this->parseCjkName($name);
            return $this;
        }

        $nameParts = explode(' ', $name);
        $this->firstName = array_shift($nameParts);

        if (empty($this->firstName) || count($nameParts) == 0)
            return $this;

        if (count($nameParts) == 1)
        {
            $this->lastName = $nameParts[0];
        }
        else
        {
            $this->lastName = array_pop($nameParts);
            $this->middleName = implode(" ", $nameParts);
        }

        return $this;
    }

    public function getFirstname()
    {
        return $this->firstName;
    }

    public function getMiddlename()
    {
        return $this->middleName;
    }

    public function getLastname()
    {
        return $this->lastName;
    }

    /**
     * Splits a CJK (Chinese / Japanese / Korean) name into surname and given name.
     *
     * CJK names are written surname-first without spaces. Most surnames are a
     * single character (e.g. 李 in 李小龙), but compound surnames of two
     * characters (e.g. 欧阳, 司马) are also common.
     */
    private function parseCjkName($name)
    {
        $name = preg_replace('!\s+!', '', $name); // Remove spaces between CJK characters
        $characters = preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $count = count($characters);

        if ($count <= 1)
            return; // A single character cannot be split

        // A compound surname (e.g. 欧阳娜娜, 司马光) takes priority
        if ($count >= 3 && $this->isCompoundSurname($characters[0] . $characters[1]))
        {
            $this->lastName = $characters[0] . $characters[1];
            $this->firstName = implode("", array_slice($characters, 2));
        }
        else
        {
            // Most surnames are a single character (e.g. 李 in 李小龙)
            $this->lastName = $characters[0];
            $this->firstName = implode("", array_slice($characters, 1));
        }
    }

    private function isCjkName($name)
    {
        // CJK Unified Ideographs, Extension A, Extension B and Compatibility Ideographs
        return (bool)preg_match(
            '/^[\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{20000}-\x{2A6DF}\x{F900}-\x{FAFF}]+$/u',
            $name
        );
    }

    private function isCompoundSurname($surname)
    {
        static $compoundSurnames = [
            '欧阳', '司马', '上官', '诸葛', '东方', '皇甫', '尉迟', '公孙', '慕容',
            '夏侯', '宇文', '长孙', '轩辕', '令狐', '钟离', '闾丘', '司徒', '司空',
            '亓官', '司寇', '子车', '颛孙', '端木', '巫马', '公西', '漆雕', '乐正',
            '壤驷', '公良', '拓跋', '夹谷', '宰父', '谷梁', '段干', '百里', '东郭',
            '南门', '呼延', '归海', '羊舌', '微生', '岳帅', '梁丘', '左丘', '东门',
            '西门', '南宫', '第五', '独孤', '鲜于', '单于', '万俟', '申屠'
        ];

        return in_array($surname, $compoundSurnames);
    }
}
