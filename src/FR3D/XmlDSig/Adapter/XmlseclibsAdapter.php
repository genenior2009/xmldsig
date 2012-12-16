<?php

namespace FR3D\XmlDSig\Adapter;

use DOMDocument;
use XMLSecEnc;
use RuntimeException;
use XMLSecurityKey;
use XMLSecurityDSig;
use UnexpectedValueException;

/**
 * XmlDSig adapter based on "xmlseclibs" library
 *
 * http://code.google.com/p/xmlseclibs/
 */
class XmlseclibsAdapter implements AdapterInterface
{
    /**
     * Private key
     *
     * @var string
     */
    protected $privateKey;

    /**
     * Public key
     *
     * @var string
     */
    protected $publicKey;

    /**
     * Signature algorithm URI. By default RSA with SHA1
     *
     * @var string
     */
    protected $keyAlgorithm = self::RSA_SHA1;

    /**
     * Digest algorithm URI. By default SHA1
     *
     * @var string
     * @see AdapterInterface::SHA1
     */
    protected $digestAlgorithm = self::SHA1;

    /**
     * Canonical algorithm URI. By default C14N
     *
     * @var string
     * @see AdapterInterface::XML_C14N
     */
    protected $canonicalMethod = self::XML_C14N;

    /**
     * Transforms list
     *
     * @var array
     * @see AdapterInterface::ENVELOPED
     */
    protected $transforms = array();


    public function setPrivateKey($privateKey, $algorithmType = self::RSA_SHA1)
    {
        $this->privateKey   = $privateKey;
        $this->keyAlgorithm = $algorithmType;

        return $this;
    }

    public function setPublicKey($publicKey)
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    public function getPublicKey()
    {
        if (!$this->publicKey && $this->privateKey) {
            // Extract public key from private key
            openssl_pkey_export(
                openssl_pkey_get_public($this->privateKey),
                $this->publicKey
            );
        }

        return $this->publicKey;
    }

    public function getKeyAlgorithm()
    {
        return $this->keyAlgorithm;
    }

    public function setDigestAlgorithm($algorithmType = self::SHA1)
    {
        $this->digestAlgorithm = $algorithmType;

        return $this;
    }

    public function setCanonicalMethod($methodType = self::XML_C14N)
    {
        $this->canonicalMethod = $methodType;

        return $this;
    }

    public function addTransform($transformType)
    {
        $this->transforms[] = $transformType;

        return $this;
    }

    public function sign(DOMDocument $data)
    {
        if (null === $this->privateKey) {
            throw new RuntimeException(
                'Missing private key. Use setPrivateKey to set one.'
            );
        }

        $objKey = new XMLSecurityKey(
            $this->keyAlgorithm,
            array(
                 'type' => 'private',
            )
        );
        $objKey->loadKey($this->privateKey);

        $objXMLSecDSig = new XMLSecurityDSig();
        $objXMLSecDSig->setCanonicalMethod($this->canonicalMethod);
        $objXMLSecDSig->addReference($data, $this->digestAlgorithm, $this->transforms, array('force_uri' => true));
        $objXMLSecDSig->sign($objKey, $data->documentElement);

        /* Add associated public key */
        if ($this->getPublicKey()) {
            $objXMLSecDSig->add509Cert($this->getPublicKey());
        }
    }

    public function verify(DOMDocument $data)
    {
        $objKey        = null;
        $objXMLSecDSig = new XMLSecurityDSig();
        $objDSig       = $objXMLSecDSig->locateSignature($data);
        if (!$objDSig) {
            throw new UnexpectedValueException('Signature DOM element not found.');
        }
        $objXMLSecDSig->canonicalizeSignedInfo();

        if (!$this->getPublicKey()) {
            // try to get the public key from the certificate
            $objKey = $objXMLSecDSig->locateKey();
            if ($objKey) {
                XMLSecEnc::staticLocateKeyInfo($objKey, $objDSig);
                $this->publicKey    = $objKey->getX509Certificate();
                $this->keyAlgorithm = $objKey->getAlgorith();
            } else {
                throw new RuntimeException(
                    'There is no set either private key or public key for signature verification.'
                );
            }
        }

        if (!$objKey) {
            $objKey = new XMLSecurityKey(
                $this->keyAlgorithm,
                array(
                     'type' => 'public',
                )
            );
            $objKey->loadKey($this->getPublicKey());
        }

        //$objXMLSecDSig->validateReference();
        return (1 === $objXMLSecDSig->verify($objKey));
    }
}