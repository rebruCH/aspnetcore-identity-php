<?php

namespace MDHearing\AspNetCore\Identity;

/**
 * Implements the standard Identity password hashing.
 */
class PasswordHasher implements IPasswordHasher
{
    /* =======================
     * HASHED PASSWORD FORMATS
     * =======================
     *
     * Version 2:
     * PBKDF2 with HMAC-SHA1, 128-bit salt, 256-bit subkey, 1000 iterations.
     * (See also: SDL crypto guidelines v5.1, Part III)
     * Format: { 0x00, salt, subkey }
     *
     * Version 3:
     * PBKDF2 with HMAC-SHA256, 128-bit salt, 256-bit subkey, 10000 iterations.
     * Format: { 0x01, prf (UInt32), iter count (UInt32), salt length (UInt32), salt, subkey }
     * (All UInt32s are stored big-endian.)
     */

    private $_compatibilityMode;
    private $_iterCount;

    /**
      * Creates a new instance of <see cref="PasswordHasher{TUser}"/>.
      *
      * @param $optionsAccessor The options for this instance.
      */
    public function __construct($compatibilityMode = PasswordHasherCompatibilityMode::IdentityV3, $iterationsCount = 10000)
    {
        $this->_compatibilityMode = $compatibilityMode;
        switch ($this->_compatibilityMode) {
            case PasswordHasherCompatibilityMode::IdentityV2:
                // nothing else to do
                break;

            case PasswordHasherCompatibilityMode::IdentityV3:
                $this->_iterCount = $iterationsCount;
                if ($this->_iterCount < 1) {
                    throw new \InvalidArgumentException('Invalid password hasher iteration count.');
                }
                break;

            default:
                throw new \InvalidArgumentException('Invalid password hasher compatibility mode.');
        }
    }

    /**
      * Returns a hashed representation of the supplied <paramref name="password"/> for the specified <paramref name="user"/>.
      *
      * @param $password The password to hash.
      *
      * @returns A hashed representation of the supplied password for the specified user.
      */
    public function HashPassword($password)
    {
        if ($password == null) {
            throw new ArgumentNullException('password');
        }

        if ($this->_compatibilityMode == PasswordHasherCompatibilityMode::IdentityV2) {
            return base64_encode(self::HashPasswordV2($password));
        } else {
            return base64_encode($this->HashPasswordV3($password));
        }
    }

    private static function HashPasswordV2($password)
    {
        $Pbkdf2Prf = KeyDerivationPrf::HMACSHA1; // default for Rfc2898DeriveBytes
        $Pbkdf2IterCount = 1000; // default for Rfc2898DeriveBytes
        $Pbkdf2SubkeyLength = intdiv(256, 8); // 256 bits
        $SaltSize = intdiv(128, 8); // 128 bits

        // Produce a version 2 (see comment above) text hash.
        $salt = random_bytes($SaltSize);
        $subkey = hash_pbkdf2(KeyDerivationPrf::ALGO_NAME[$Pbkdf2Prf], $password, $salt, $Pbkdf2IterCount, $Pbkdf2SubkeyLength, true);

        $outputBytes = chr(0) . $salt . $subkey;

        return $outputBytes;
    }

    private function HashPasswordV3($password)
    {
        $prf = KeyDerivationPrf::HMACSHA256;
        $iterCount = $this->_iterCount;
        $saltSize = intdiv(128, 8);
        $numBytesRequested = intdiv(256, 8);

        // Produce a version 3 (see comment above) text hash.
        $salt = random_bytes($saltSize);
        $subkey = hash_pbkdf2(KeyDerivationPrf::ALGO_NAME[$prf], $password, $salt, $iterCount, $numBytesRequested, true);

        $outputBytes = '';
        $outputBytes{0} = chr(0x01); // format marker
        self::WriteNetworkByteOrder($outputBytes, 1, $prf);
        self::WriteNetworkByteOrder($outputBytes, 5, $iterCount);
        self::WriteNetworkByteOrder($outputBytes, 9, $saltSize);

        $outputBytes .= $salt;
        $outputBytes .= $subkey;

        return $outputBytes;
    }

    /**
      * Returns a PasswordVerificationResult indicating the result of a password hash comparison.
      *
      * @param $hashedPassword The hash value for a user's stored password.
      * @param $providedPassword The password supplied for comparison.
      *
      * @returns A PasswordVerificationResult indicating the result of a password hash comparison.
      *
      * Implementations of this method should be time consistent.
      */
    public function VerifyHashedPassword($hashedPassword, $providedPassword)
    {
        if ($hashedPassword == null) {
            throw new \InvalidArgumentException('hashedPassword is null');
        }

        if ($providedPassword == null) {
            throw new \InvalidArgumentException('providedPassword is null');
        }

        $decodedHashedPassword = base64_decode($hashedPassword);

        // read the format marker from the hashed password
        if (strlen($decodedHashedPassword) == 0) {
            return PasswordVerificationResult::Failed;
        }

        switch (ord($decodedHashedPassword{0})) {
            case 0x00:
                if (self::VerifyHashedPasswordV2($decodedHashedPassword, $providedPassword)) {
                    // This is an old password hash format - the caller needs to rehash if we're not running in an older compat mode.
                    return ($this->_compatibilityMode == PasswordHasherCompatibilityMode::IdentityV3)
                        ? PasswordVerificationResult::SuccessRehashNeeded
                        : PasswordVerificationResult::Success;
                } else {
                    return PasswordVerificationResult::Failed;
                }

            case 0x01:
                $embeddedIterCount;
                if (self::VerifyHashedPasswordV3($decodedHashedPassword, $providedPassword, $embeddedIterCount)) {
                    // If this hasher was configured with a higher iteration count, change the entry now.
                    return ($embeddedIterCount < $this->_iterCount)
                        ? PasswordVerificationResult::SuccessRehashNeeded
                        : PasswordVerificationResult::Success;
                } else {
                    return PasswordVerificationResult::Failed;
                }

            default:
                return PasswordVerificationResult::Failed; // unknown format marker
        }
    }

    private static function VerifyHashedPasswordV2($hashedPassword, $password)
    {
        $Pbkdf2Prf = KeyDerivationPrf::HMACSHA1; // default for Rfc2898DeriveBytes
        $Pbkdf2IterCount = 1000; // default for Rfc2898DeriveBytes
        $Pbkdf2SubkeyLength = intdiv(256, 8); // 256 bits
        $SaltSize = intdiv(128, 8); // 128 bits

        // We know ahead of time the exact length of a valid hashed password payload.
        if (strlen($hashedPassword) != 1 + $SaltSize + $Pbkdf2SubkeyLength) {
            return false; // bad size
        }

        $salt = substr($hashedPassword, 1, $SaltSize);

        $expectedSubkey = substr($hashedPassword, 1 + $SaltSize, $Pbkdf2SubkeyLength);

        // Hash the incoming password and verify it
        $actualSubkey = hash_pbkdf2(KeyDerivationPrf::ALGO_NAME[$Pbkdf2Prf], $password, $salt, $Pbkdf2IterCount, $Pbkdf2SubkeyLength, true);

        return $actualSubkey === $expectedSubkey;
    }

    private static function VerifyHashedPasswordV3($hashedPassword, $password, &$iterCount)
    {
        $iterCount = 0;

        // Read header information
        $prf = self::ReadNetworkByteOrder($hashedPassword, 1);
        $iterCount = self::ReadNetworkByteOrder($hashedPassword, 5);
        $saltLength = self::ReadNetworkByteOrder($hashedPassword, 9);

        // Read the salt: must be >= 128 bits
        if ($saltLength < intdiv(128, 8)) {
            return false;
        }

        $salt = substr($hashedPassword, 13, $saltLength);

        // Read the subkey (the rest of the payload): must be >= 128 bits
        $subkeyLength = strlen($hashedPassword) - 13 - strlen($salt);
        if ($subkeyLength < intdiv(128, 8)) {
            return false;
        }

        $expectedSubkey = substr($hashedPassword, 13 + strlen($salt), $subkeyLength);

        // Hash the incoming password and verify it
        $actualSubkey = hash_pbkdf2(KeyDerivationPrf::ALGO_NAME[$prf], $password, $salt, $iterCount, $subkeyLength, true);

        return $actualSubkey === $expectedSubkey;
    }

    private static function WriteNetworkByteOrder(&$buffer, $offset, $value)
    {
        $buffer{$offset} = chr($value >> 24);
        $buffer{$offset + 1} = chr(($value >> 16) & 0xFF);
        $buffer{$offset + 2} = chr(($value >> 8) & 0xFF);
        $buffer{$offset + 3} = chr($value & 0xFF);
    }

    private static function ReadNetworkByteOrder($buffer, $offset)
    {
        return ord($buffer{$offset}) << 24
            | ord($buffer{$offset + 1}) << 16
            | ord($buffer{$offset + 2}) << 8
            | ord($buffer{$offset + 3});
    }
}

