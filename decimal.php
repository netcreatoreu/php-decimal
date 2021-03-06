<?php
/*
 * Arbitrary-precision exact numeric library for PHP.
 *
 * This library wraps the PHP 'bcmath' extension, offering a more natural and 
 * convenient API by automatically determining a sensible scale to use for 
 * arithmetic results, and by automatically removing superfluous precision from 
 * numbers.
 *
 * See the README.md file in the top-level directory of this library for more 
 * details.
 *
 * This library is licensed under the "BSD 2-clause" open source license, the 
 * full text of which is available in the file 'LICENSE' in the top-level 
 * directory of this library.
 */
namespace Direvus\Decimal;

const ZERO = '0';
const EXP_MARK = 'e';
const RADIX_MARK = '.';

class Decimal {
    public $digits;
    public $exponent = 0;
    public $negative = false;

    public static $raw_formatter;
    public static $zero;
    public static $one;

    /**
     * @param mixed $value An integer, float, Decimal or numeric string.
     */
    public function __construct($value=0){
        if($value instanceof Decimal){
            $this->copy($value);
        }else{
            $clean = self::cleanValue($value);
            if($clean[0] == '-'){
                $this->negative = true;
                $clean = substr($clean, 1);
            }
            // If the value contains an exponent specifier, parse and remove 
            // it.
            $clean = strtolower($clean);
            $pos = strrpos($clean, EXP_MARK);
            if($pos !== false){
                $this->exponent = (int) substr($clean, $pos + 1);
                $clean = substr($clean, 0, $pos);
            }
            // Remove the period and decrease the exponent by one for each 
            // digit following the period.
            $pos = strpos($clean, RADIX_MARK);
            if($pos !== false){
                $clean = substr($clean, 0, $pos) . substr($clean, $pos + 1);
                $this->exponent += ($pos - strlen($clean));
            }
            // Discard leading zeroes.
            $clean = ltrim($clean, ZERO);
            // For integer values (non-negative exponents), remove trailing 
            // zeroes and increase the exponent by one for each digit removed.
            if($this->exponent >= 0){
                $len = strlen($clean);
                $clean = rtrim($clean, ZERO);
                $this->exponent += ($len - strlen($clean));
            }
            if($clean == ''){
                $this->digits = ZERO;
            }else{
                $this->digits = $clean;
            }
        }
    }

    /**
     * Make this object equal to $source by copying its properties.
     *
     * @param Decimal $source
     */
    public function copy(Decimal $source){
        $this->digits   = $source->digits;
        $this->exponent = $source->exponent;
        $this->negative = $source->negative;
    }

    /**
     * Return the number of digits after the decimal point required to fully 
     * represent this value.
     *
     * @return int
     */
    public function getScale(){
        if($this->exponent >= 0){
            return 0;
        }else{
            return -$this->exponent;
        }
    }

    /**
     * Compare this Decimal with a value.
     *
     * @param mixed $value
     * @return int -1 if the instance is less than the $value,
     *         0 if the instance is equal to $value, or
     *         1 if the instance is greater than $value.
     */
    public function compare($value){
        $decimal = self::make($value);
        $scale = \max($this->getScale(), $decimal->getScale());
        return bccomp($this, $decimal, $scale);
    }

    public function equals($value){
        return ($this->compare($value) == 0);
    }

    public function greaterThan($value){
        return ($this->compare($value) > 0);
    }

    public function lessThan($value){
        return ($this->compare($value) < 0);
    }

    public function isZero(){
        return $this->equals(0);
    }

    public function positive(){
        return $this->greaterThan(0);
    }

    public function negative(){
        return $this->lessThan(0);
    }

    public function eq($value){
        return $this->equals($value);
    }

    public function lt($value){
        return $this->lessThan($value);
    }

    public function gt($value){
        return $this->greaterThan($value);
    }

    public function ge($value){
        return ($this->compare($value) >= 0);
    }

    public function le($value){
        return ($this->compare($value) <= 0);
    }

    /*
     * Return the absolute value of this Decimal as a new Decimal.
     */
    public function abs(){
        $result = new Decimal($this);
        $result->negative = false;
        return $result;
    }

    /*
     * Return the negation of this Decimal as a new Decimal.
     */
    public function negation(){
        $result = new Decimal($this);
        $result->negative = !$this->negative;
        return $result;
    }

    /*
     * Add $value to this Decimal and return the sum as a new Decimal.
     */
    public function add($value, $scale=null){
        $decimal = self::make($value);
        $scale = self::resultScale($this, $decimal, $scale);
        return new Decimal(bcadd($this, $decimal, $scale));
    }

    /*
     * Subtract $value from this Decimal and return the difference as a new 
     * Decimal.
     */
    public function subtract($value, $scale=null){
        $decimal = self::make($value);
        $scale = self::resultScale($this, $decimal, $scale);
        return new Decimal(bcsub($this, $decimal, $scale));
    }

    public function sub($value, $scale=null){
        return $this->subtract($value, $scale);
    }

    /*
     * Multiply this Decimal by $value and return the product as a new Decimal.
     */
    public function multiply($value, $scale=null){
        $decimal = self::make($value);
        if(!self::scaleValid($scale)){
            $scale = $this->getScale() + $decimal->getScale();
        }
        return new Decimal(bcmul($this, $decimal, $scale));
    }

    public function mul($value, $scale=null){
        return $this->multiply($value, $scale);
    }

    /**
     * Divide this Decimal by $value and return the quotient as a new Decimal.
     *
     * @throws \DomainException if $value is zero.
     */
    public function divide($value, $scale=null){
        $decimal = self::make($value);
        if($decimal->isZero()){
            throw new \DomainException("Cannot divide by zero.");
        }
        $scale = self::resultScale($this, $decimal, $scale);
        return new Decimal(bcdiv($this, $decimal, $scale));
    }

    public function div($value, $scale=null){
        return $this->divide($value, $scale);
    }

    /*
     * Return the inverse (1/x) of this Decimal as a new Decimal.
     *
     * The default scale of the division will be equal to the exponent of this
     * Decimal plus one, if it is positive, otherwise it will be zero.
     */
    public function inverse($scale=null){
        if(!self::scaleValid($scale)){
            $scale = \max(0, $this->exponent + 1);
        }
        $num = self::one();
        return $num->divide($this, $scale);
    }

    /*
     * Increase this Decimal in-place by the given argument(s).
     *
     * Traversable arguments are processed recursively.
     */
    public function increase(){
        $args = func_get_args();
        foreach($args as $arg){
            if(is_array($arg) || $arg instanceof \Traversable){
                foreach($arg as $element){
                    $this->increase($element);
                }
            }else{
                $this->copy($this->add($arg));
            }
        }
    }

    /*
     * Decrease this Decimal in-place by the given argument(s).
     *
     * Traversable arguments are processed recursively.
     */
    public function decrease(){
        $args = func_get_args();
        foreach($args as $arg){
            if(is_array($arg) || $arg instanceof \Traversable){
                foreach($arg as $element){
                    $this->decrease($element);
                }
            }else{
                $this->copy($this->sub($arg));
            }
        }
    }

    /*
     * Flip the sign of this Decimal, and return whether the result is 
     * negative.
     */
    public function negate(){
        $this->negative = !$this->negative;
        return $this->negative;
    }

    /**
     * Return a new Decimal which represents this value in its canonical form.
     *
     * The canonical form is the form that uses the minimum possible number of
     * digits without any loss of precision to the value.
     *
     * A zero value will always be returned as a positive Decimal -- it is
     * possible to represent "negative zero" using a Decimal object, but the
     * treatment of zero in this library is unsigned, and the canonical
     * representation of zero is always positive zero.
     *
     * @return Decimal
     */
    public function compress(){
        $result = clone $this;
        $len = strlen($result->digits);
        $result->digits = ltrim($result->digits, ZERO);
        $newlen = strlen($result->digits);
        if($newlen > 0){
            $result->exponent -= $len - $newlen;
        }else{
            $result->digits = ZERO;
            $result->exponent = 0;
            $result->negative = false;
            return $result;
        }
        $result->digits = rtrim($result->digits, ZERO);
        $result->exponent += $newlen - strlen($result->digits);
        return $result;
    }

    /**
     * Return a new Decimal which expresses this value at the given exponent.
     *
     * If this Decimal cannot be fully expressed using the target exponent, 
     * round the result using $method, which has the same meaning as in PHP's 
     * built-in round function.
     *
     * @param int $exponent The target exponent.
     * @param int $method The rounding method to use, if necessary.
     * @return Decimal
     */
    public function quantize($exponent, $method=PHP_ROUND_HALF_UP){
        $result = $this->compress();
        $count = $result->exponent - $exponent;
        if($exponent < $result->exponent){
            $result->digits .= self::zeroes($count);
            $result->exponent = $exponent;
        }elseif($exponent > $result->exponent){
            if($result->exponent < 0){
                if(strlen($result->digits) <= abs($count)){
                    $prev_even = true;
                }else{
                    $prev = (int) substr($result->digits, $count - 1, 1);
                    $prev_even = ($prev % 2 == 0);
                }
                $roundoff = new Decimal;
                if($method == PHP_ROUND_HALF_DOWN ||
                        ($method == PHP_ROUND_HALF_EVEN && $prev_even) ||
                        ($method == PHP_ROUND_HALF_ODD && !$prev_even)){
                    $roundoff->digits = '4';
                }else{
                    $roundoff->digits = '5';
                }
                $roundoff->exponent = ($exponent - 1);
                $roundoff->negative = $result->negative;
                $result->increase($roundoff);
                $result->digits = substr($result->digits, 0, $count);
                if(strlen($result->digits) == 0){
                    $result->digits = ZERO;
                }
            }else{
                $result->digits = self::zeroes(-$count) . $result->digits;
            }
            $result->exponent = $exponent;
        }
        return $result;
    }

    /**
     * Return a new Decimal from this instance which has been rounded.
     *
     * @param int $places Number of decimal places to round to.
     * @param int $method The method to use for rounding, per PHP's built-in
     *         round() function.
     * @return Decimal
     */
    public function round($places, $method=PHP_ROUND_HALF_UP){
        return $this->quantize(\min(0, -$places), $method);
    }

    /**
     * Return a basic string representation of this Decimal.
     *
     * The output of this method is guaranteed to yield exactly the same value
     * if fed back into the Decimal constructor.
     *
     * The format of the string is an optional negative sign marker, followed
     * by one or more digits, followed optionally by the radix mark and one or
     * more digits.
     *
     * @return string
     */
    public function __toString(){
        if(!self::$raw_formatter instanceof Formatter){
            self::$raw_formatter = new Formatter(null, '', RADIX_MARK);
        }
        return self::$raw_formatter->format($this);
    }

    /**
     * Return some approximation of this Decimal as a PHP native float.
     *
     * Due to the nature of binary floating-point, some valid values of Decimal
     * will not have any finite representation as a float, and some valid
     * values of Decimal will be out of the range handled by floats.  You have
     * been warned.
     *
     * @return float
     */
    public function toFloat(){
        return (float) (string) $this;
    }

    /**
     * Return this Decimal formatted as a string.
     *
     * @param int $places Number of fractional digits to show.
     *         The value will be rounded as necessary to accomodate this
     *         setting, using the default rounding method.  If $places is null,
     *         the result will use as many places as required to show the value
     *         in full.
     * @param string $grouping String to use as a thousands separator.
     * @param string $radix_mark String to separate the integer part from
     *         the fractional part, also known as a 'decimal point'.
     * @return string
     */
    public function format($places=null, $grouping='', $radix_mark=RADIX_MARK){
        $f = new Formatter($places, $grouping, $radix_mark);
        return $f->format($this);
    }

    /**
     * Return a Decimal instance from the given value.
     *
     * If the value is already a Decimal instance, then return it unmodified.
     * Otherwise, create a new Decimal instance from the given value and return
     * it.
     *
     * @param mixed $value
     * @return Decimal
     */
    public static function make($value){
        if($value instanceof Decimal){
            return $value;
        }else{
            return new Decimal($value);
        }
    }

    /**
     * Return the given number as a string with irrelevant characters removed.
     *
     * All characters other than digits, hyphen, the radix marker and the
     * exponent marker are removed entirely.
     *
     * @param mixed $value
     * @return string
     * @throw \DomainException if the value is not a valid numeric
     *         representation.
     */
    public static function cleanValue($value){
        if(is_int($value) || is_float($value)){
            return (string) $value;
        }else{
            $chars = '\d' . RADIX_MARK . EXP_MARK . '-';
            $clean = preg_replace("/[^$chars]/i", '', $value);
            $clean = rtrim($clean, RADIX_MARK);
            $pattern = '/^-?\d+(?:[' . RADIX_MARK . ']\d*)?(?:' .
                    EXP_MARK . '-?\d*)?$/i';
            if(!preg_match($pattern, $clean)){
                throw new \DomainException(
                    "Invalid Decimal value '$value'; " .
                    "must contain at least one digit, optionally preceeded " .
                    "by a sign specifier, optionally followed by " .
                    RADIX_MARK . " and a fractional part, optionally followed " .
                    "by " . EXP_MARK . " and an integer exponent.");
            }
            return $clean;
        }
    }

    /**
     * Return the greatest of the arguments.
     *
     * @param mixed,...
     * @return Decimal
     */
    public static function max(){
        $args = func_get_args();
        $result = null;
        foreach($args as $arg){
            $dec = self::make($arg);
            if($result === null || $result->lt($dec)){
                $result = $dec;
            }
        }
        return $result;
    }

    /**
     * Return the least of the arguments.
     *
     * @param mixed,...
     * @return Decimal
     */
    public static function min(){
        $args = func_get_args();
        $result = null;
        foreach($args as $arg){
            $dec = self::make($arg);
            if($result === null || $result->gt($dec)){
                $result = $dec;
            }
        }
        return $result;
    }

    /**
     * Return whether $scale is valid as a decimal operation scale.
     *
     * @param int $scale
     * @return bool
     */
    public static function scaleValid($scale){
        return (is_int($scale) && $scale >= 0);
    }

    /**
     * Return zero as a Decimal.
     *
     * @return Decimal
     */
    public static function zero(){
        if(!self::$zero instanceof Decimal){
            self::$zero = new Decimal(0);
        }
        return self::$zero;
    }

    /**
     * Return the value one as a Decimal.
     *
     * @return Decimal
     */
    public static function one(){
        if(!self::$one instanceof Decimal){
            self::$one = new Decimal(1);
        }
        return self::$one;
    }

    /*
     * Return an appropriate scale for an arithmetic operation on two Decimals.
     *
     * If $scale is specified and is a valid positive integer, return it.
     * Otherwise, return the higher of the scales of the operands.
     *
     * @param Decimal $a
     * @param Decimal $b
     * @param int|null $scale
     * @return int
     */
    public static function resultScale(Decimal $a, Decimal $b, $scale=null){
        if(!self::scaleValid($scale)){
            $scale = \max($a->getScale(), $b->getScale());
        }
        return $scale;
    }

    /**
     * Return a string of zeroes of length $length.
     *
     * @param int $length
     * @return string
     */
    public static function zeroes($length){
        return \str_repeat(ZERO, $length);
    }
}

class Formatter {
    public $places;
    public $grouping;
    public $radix_mark;

    public function __construct(
            $places=null, $grouping='', $radix_mark=RADIX_MARK){
        $this->places = $places;
        $this->grouping = $grouping;
        $this->radix_mark = $radix_mark;
    }

    public function format($decimal){
        if($decimal === '' || $decimal === null){
            $decimal = 0;
        }
        if($decimal instanceof Decimal){
            $decimal = $decimal->compress();
        }else{
            $decimal = new Decimal($decimal);
        }
        if($this->places !== null && $this->places != $decimal->getScale()){
            $decimal = $decimal->round($this->places);
        }
        if($decimal->exponent >= 0){
            $fill = Decimal::zeroes($decimal->exponent);
            $intpart = $decimal->digits . $fill;
            $fracpart = '';
        }else{
            $intpart = substr($decimal->digits, 0, $decimal->exponent);
            $fracpart = substr($decimal->digits, $decimal->exponent);
            $len = strlen($fracpart);
            $scale = $decimal->getScale();
            if($len < $scale){
                $fracpart = Decimal::zeroes($scale - $len) . $fracpart;
            }
        }
        if($intpart == ''){
            $intpart = ZERO;
        }
        $grouplen = strlen($this->grouping);
        if($grouplen > 0){
            for($i = 3; $i < strlen($intpart); $i += 3 + $grouplen){
                $intpart = substr_replace($intpart, $this->grouping, -$i, 0);
            }
        }
        $result = '';
        if($decimal->negative){
            $result = '-';
        }
        $result .= $intpart;
        if(strlen($fracpart) > 0){
            $result .= $this->radix_mark . $fracpart;
        }
        return $result;
    }
}

class MoneyFormatter extends Formatter {
    public function __construct(
            $places=2, $grouping='', $radix_mark=RADIX_MARK){
        parent::__construct($places, $grouping, $radix_mark);
    }
}

class GroupedMoneyFormatter extends MoneyFormatter {
    public function __construct(
            $places=2, $grouping=',', $radix_mark=RADIX_MARK){
        parent::__construct($places, $grouping, $radix_mark);
    }
}
