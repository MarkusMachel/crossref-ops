<?php

/**
 * @file plugins/generic/crossref/filter/PreprintCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PreprintCrossrefXmlFilter
 *
 * @brief Class that converts a Preprint to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\author\Author;
use APP\core\Application;
use APP\core\Request;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\author\contributorRole\ContributorRoleIdentifier;
use PKP\author\contributorRole\ContributorType;
use PKP\core\Dispatcher;
use PKP\dataCitation\DataCitation;
use PKP\i18n\LocaleConversion;
use PKP\submission\PKPSubmission;

class PreprintCrossrefXmlFilter extends \PKP\plugins\importexport\native\filter\NativeExportFilter
{
    /**
     * Constructor
     *
     * @param \PKP\filter\FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('Crossref XML preprint export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @see Filter::process()
     *
     * @param array $pubObjects Array of Submissions
     *
     * @return \DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and append the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        foreach ($pubObjects as $pubObject) {
            $publications = $pubObject->getData('publications')->toArray();
            // Use array reverse so that the latest version of the submission is first in the xml output and the DOI relations do not cause an error with Crossref
            $publications = array_reverse($publications, true);
            foreach ($publications as $publication) {
                if ($publication->getDoi() && $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED) {
                    $postedContentNode = $this->createPostedContentNode($doc, $publication, $pubObject);
                    $bodyNode->appendChild($postedContentNode);
                }
            }
        }
        return $doc;
    }

    //
    // Submission conversion functions
    //

    /**
     * Create and return the root node 'doi_batch'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */
    public function createRootNode($doc)
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:jats', $deployment->getJATSNamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ai', $deployment->getAINamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:rel', $deployment->getRELNamespace());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:fr', $deployment->getFundrefNamespace());
        $rootNode->setAttribute('version', $deployment->getXmlSchemaVersion());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create and return the head node 'head'.
     *
     * @param \DOMDocument $doc
     *
     * @return \DOMElement
     */
    public function createHeadNode($doc)
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $headNode = $doc->createElementNS($deployment->getNamespace(), 'head');
        $headNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'doi_batch_id', htmlspecialchars($context->getData('acronym', $context->getPrimaryLocale()) . '_' . time(), ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'timestamp', date('YmdHisv')));
        $depositorNode = $doc->createElementNS($deployment->getNamespace(), 'depositor');
        $depositorName = $plugin->getSetting($context->getId(), 'depositorName');
        if (empty($depositorName)) {
            $depositorName = $context->getData('supportName');
        }
        $depositorEmail = $plugin->getSetting($context->getId(), 'depositorEmail');
        if (empty($depositorEmail)) {
            $depositorEmail = $context->getData('supportEmail');
        }
        $depositorNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'depositor_name', htmlspecialchars($depositorName, ENT_COMPAT, 'UTF-8')));
        $depositorNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'email_address', htmlspecialchars($depositorEmail, ENT_COMPAT, 'UTF-8')));
        $headNode->appendChild($depositorNode);
        $publisherServer = $context->getLocalizedName();
        $headNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'registrant', htmlspecialchars($publisherServer, ENT_COMPAT, 'UTF-8')));
        return $headNode;
    }

    /**
     * Create and return the posted content node 'posted_content'.
     *
     * @param \DOMDocument $doc
     * @param Publication $publication
     *
     * @return \DOMElement
     */
    public function createPostedContentNode($doc, $publication, $submission)
    {
        assert($publication instanceof Publication);
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $request = Application::get()->getRequest();

        $locale = $publication->getData('locale');

        $postedContentNode = $doc->createElementNS($deployment->getNamespace(), 'posted_content');
        $postedContentNode->setAttribute('type', 'preprint');
        $postedContentNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));

        // contributors
        $contributorsNode = $this->createContributorsNode($doc, $publication);
        if ($contributorsNode->hasChildNodes()) {
            $postedContentNode->appendChild($contributorsNode);
        }

        // Titles
        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title'));
        $this->appendFaceMarkup($doc, $node, $publication->getLocalizedTitle($submission->getData('locale'), 'html'));
        if ($subtitle = $publication->getLocalizedSubTitle($submission->getData('locale'), 'html')) {
            $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle'));
            $this->appendFaceMarkup($doc, $node, $subtitle);
        }
        $postedContentNode->appendChild($titlesNode);

        // Posted date
        $postedContentNode->appendChild($this->createPostedDateNode($doc, $publication->getData('datePublished')));

        // abstract
        $abstracts = $publication->getData('abstract') ?: [];
        foreach($abstracts as $lang => $abstract) {
            $abstractNode = $doc->createElementNS($deployment->getJATSNamespace(), 'jats:abstract');
            $abstractNode->setAttributeNS($deployment->getXMLNamespace(), 'xml:lang', LocaleConversion::toBcp47($lang));
            $abstractNode->appendChild($doc->createElementNS($deployment->getJATSNamespace(), 'jats:p', htmlspecialchars(html_entity_decode(strip_tags($abstract), ENT_COMPAT, 'UTF-8'), ENT_COMPAT, 'utf-8')));
            $postedContentNode->appendChild($abstractNode);
        }

        // fr:program (FundRef)
        $this->appendFundrefNode($doc, $postedContentNode, $submission);

        // license
        if ($publication->getData('licenseUrl')) {
            $licenseNode = $doc->createElementNS($deployment->getAINamespace(), 'ai:program');
            $licenseNode->setAttribute('name', 'AccessIndicators');
            $licenseNode->appendChild($doc->createElementNS($deployment->getAINamespace(), 'ai:license_ref', htmlspecialchars($publication->getData('licenseUrl'), ENT_COMPAT, 'UTF-8')));
            $postedContentNode->appendChild($licenseNode);
        }

        // DOI relations: if this version has a vorDoi or different DOI than the current publication (i.e. versions and DOI versioning exits), add a relation node
        $parentDoi = $submission->getCurrentPublication()->getDoi() && $submission->getCurrentPublication()->getDoi() != $publication->getDoi() ? $submission->getCurrentPublication()->getDoi() : '';
        $vorDoi = $publication->getData('vorDoi') ? $publication->getData('vorDoi') : '';

        // rel:program — DOI versioning relations and data citation relations
        $this->appendRelationships($doc, $postedContentNode, $parentDoi, $vorDoi, $publication);

        // DOI data
        $dispatcher = $this->_getDispatcher($request);
        $url = $dispatcher->url($request, Application::ROUTE_PAGE, null, 'preprint', 'view', [$submission->getCurrentPublication()->getData('urlPath') ?? $submission->getId(), 'version', $publication->getId()], null, null, true, '');
        $postedContentNode->appendChild($this->createDOIDataNode($doc, $publication->getDoi(), $url));

        return $postedContentNode;
    }

    /**
     * Create contributors node.
     */
    public function createContributorsNode(DOMDocument $doc, Publication $publication): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $locale = $publication->getData('locale');

        $contributorsNode = $doc->createElementNS($deployment->getNamespace(), 'contributors');

        $isFirst = true;
        foreach ($publication->getData('authors') as $author) {
            /** @var Author $author */
            $contribRoleIds = $author->getContributorRoleIdentifiers();

            // Role 'other' not supported yet in 5.4.0, do not export that role and skip if no other roles are assigned.
            $contribRoleIds = array_values(array_diff($contribRoleIds, [ContributorRoleIdentifier::OTHER->getName()]));
            if (empty($contribRoleIds)) {
                continue;
            }

            // Crossref allows only one role per person_name
            // prioritize AUTHOR role if present, otherwise use the first role in the list
            // https://www.crossref.org/documentation/schema-library/markup-guide-metadata-segments/contributors/#00011
            $contributorRole = in_array(ContributorRoleIdentifier::AUTHOR->getName(), $contribRoleIds)
                ? ContributorRoleIdentifier::AUTHOR->getName()
                : $contribRoleIds[0];

            $contributorRole = strtolower(str_replace('_', '-', $contributorRole));
            $contributorType = $author->getData('contributorType');
            $sequence = $isFirst ? 'first' : 'additional';

            // Contributor type ORGANIZATION
            if ($contributorType === ContributorType::ORGANIZATION->getName()) {
                $organizationNode = $doc->createElementNS($deployment->getNamespace(), 'organization', htmlspecialchars($author->getLocalizedOrganizationName($locale), ENT_COMPAT, 'UTF-8'));
                $organizationNode->setAttribute('contributor_role', $contributorRole);
                $organizationNode->setAttribute('sequence', $sequence);
                $contributorsNode->appendChild($organizationNode);
                $isFirst = false;
                continue;
            }

            // Contributor type ANONYMOUS
            if ($contributorType === ContributorType::ANONYMOUS->getName()) {
                $anonymousNode = $doc->createElementNS($deployment->getNamespace(), 'anonymous');
                $anonymousNode->setAttribute('contributor_role', $contributorRole);
                $anonymousNode->setAttribute('sequence', $sequence);
                $this->appendAffiliationsNode($doc, $anonymousNode, $author, $locale);
                $contributorsNode->appendChild($anonymousNode);
                $isFirst = false;
                continue;
            }

            // Contributor type PERSON
            $personNameNode = $doc->createElementNS($deployment->getNamespace(), 'person_name');
            $personNameNode->setAttribute('contributor_role', $contributorRole);
            $personNameNode->setAttribute('sequence', $sequence);

            $familyNames = $author->getFamilyName(null);
            $givenNames = $author->getGivenName(null);

            // Check if both givenName and familyName is set for the submission language.
            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $personNameNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($familyNames[$locale], ENT_COMPAT, 'UTF-8')));
            } else {
                $personNameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($givenNames[$locale], ENT_COMPAT, 'UTF-8')));
            }

            $this->appendAffiliationsNode($doc, $personNameNode, $author, $locale);

            if ($author->getData('orcid')) {
                $orcidNode = $doc->createElementNS($deployment->getNamespace(), 'ORCID', $author->getData('orcid'));
                $orcidAuthenticated = $author->getData('orcidIsVerified') ? 'true' : 'false';
                $orcidNode->setAttribute('authenticated', $orcidAuthenticated);
                $personNameNode->appendChild($orcidNode);
            }

            if (!empty($familyNames[$locale]) && !empty($givenNames[$locale])) {
                $hasAltName = false;
                foreach ($familyNames as $otherLocal => $familyName) {
                    if ($otherLocal != $locale && isset($familyName) && !empty($familyName)) {
                        if (!$hasAltName) {
                            $altNameNode = $doc->createElementNS($deployment->getNamespace(), 'alt-name');
                            $personNameNode->appendChild($altNameNode);
                            $hasAltName = true;
                        }

                        $nameNode = $doc->createElementNS($deployment->getNamespace(), 'name');
                        $nameNode->setAttribute('language', \Locale::getPrimaryLanguage($otherLocal));

                        $nameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'surname', htmlspecialchars($familyName, ENT_COMPAT, 'UTF-8')));
                        if (isset($givenNames[$otherLocal]) && !empty($givenNames[$otherLocal])) {
                            $nameNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'given_name', htmlspecialchars($givenNames[$otherLocal], ENT_COMPAT, 'UTF-8')));
                        }

                        $altNameNode->appendChild($nameNode);
                    }
                }
            }

            $contributorsNode->appendChild($personNameNode);
            $isFirst = false;
        }

        return $contributorsNode;
    }

    /**
     * Append an affiliations node
     */
    public function appendAffiliationsNode(DOMDocument $doc, DOMElement $parentNode, Author $author, string $locale): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $affiliationsNode = null;
        foreach ($author->getAffiliations() as $affiliation) {
            $institutionName = $affiliation->getLocalizedName($locale);
            if (trim($institutionName ?? '') === '') {
                continue;
            }
            if ($affiliationsNode === null) {
                $affiliationsNode = $doc->createElementNS($deployment->getNamespace(), 'affiliations');
            }
            $institutionNode = $doc->createElementNS($deployment->getNamespace(), 'institution');
            $institutionNode->appendChild(
                $doc->createElementNS($deployment->getNamespace(), 'institution_name', htmlspecialchars($institutionName, ENT_COMPAT, 'UTF-8'))
            );
            if ($rorId = $affiliation->getRor()) {
                $institutionIdNode = $doc->createElementNS($deployment->getNamespace(), 'institution_id', $rorId);
                $institutionIdNode->setAttribute('type', 'ror');
                $institutionNode->appendChild($institutionIdNode);
            }
            $affiliationsNode->appendChild($institutionNode);
        }
        if ($affiliationsNode) {
            $parentNode->appendChild($affiliationsNode);
        }
    }

    /**
     * Append fr:program (FundRef) node with funding information
     */
    public function appendFundrefNode(DOMDocument $doc, DOMElement $parentNode, Submission $submission): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $funders = $submission->getData('funders');

        if ($funders->isEmpty()) {
            return;
        }

        $locale = $submission->getData('locale');

        $programNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:program');
        $programNode->setAttribute('name', 'fundref');

        foreach ($funders as $funder) {
            $groupNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:assertion');
            $groupNode->setAttribute('name', 'fundgroup');

            $funderName = $funder->getLocalizedData('name', $locale);

            $rorNode = null;
            if (!empty($funder->ror)) {
                $rorNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:assertion', $funder->ror);
                $rorNode->setAttribute('name', 'ror');
            }

            if (!empty($funderName)) {
                $funderNameNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:assertion', htmlspecialchars($funderName, ENT_COMPAT, 'UTF-8'));
                $funderNameNode->setAttribute('name', 'funder_name');
                if ($rorNode) {
                    $funderNameNode->appendChild($rorNode);
                }
                $groupNode->appendChild($funderNameNode);
            } elseif ($rorNode) {
                $groupNode->appendChild($rorNode);
            }

            if (!empty($funder->grants)) {
                foreach ($funder->grants as $grant) {
                    $awardNode = null;
                    if (!empty($grant['grantNumber'])) {
                        $awardNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:assertion', htmlspecialchars($grant['grantNumber'], ENT_COMPAT, 'UTF-8'));
                        $awardNode->setAttribute('name', 'award_number');
                    }

                    if (!empty($grant['grantDoi'])) {
                        $grantDoiNode = $doc->createElementNS($deployment->getFundrefNamespace(), 'fr:assertion', htmlspecialchars($grant['grantDoi'], ENT_COMPAT, 'UTF-8'));
                        $grantDoiNode->setAttribute('name', 'grant_doi');

                        if ($awardNode) {
                            $grantDoiNode->appendChild($awardNode);
                        }

                        $groupNode->appendChild($grantDoiNode);

                    } elseif ($awardNode) {
                        $groupNode->appendChild($awardNode);
                    }
                }
            }

            if ($groupNode->hasChildNodes()) {
                $programNode->appendChild($groupNode);
            }

        }

        if ($programNode->hasChildNodes()) {
            $parentNode->appendChild($programNode);
        }
    }

    /**
     * Create and return the posted date node 'posted_date'.
     *
     * @param \DOMDocument $doc
     * @param string $objectPostedDate
     *
     * @return \DOMElement
     */
    public function createPostedDateNode($doc, $objectPostedDate)
    {
        $deployment = $this->getDeployment();
        $postedDate = strtotime($objectPostedDate);
        $postedDateNode = $doc->createElementNS($deployment->getNamespace(), 'posted_date');
        if (date('m', $postedDate)) {
            $postedDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'month', date('m', $postedDate)));
        }
        if (date('d', $postedDate)) {
            $postedDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'day', date('d', $postedDate)));
        }
        $postedDateNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'year', date('Y', $postedDate)));
        return $postedDateNode;
    }

    /**
     * Create and return the DOI data node 'doi_data'.
     *
     * @param \DOMDocument $doc
     * @param string $doi
     * @param string $url
     *
     * @return \DOMElement
     */
    public function createDOIDataNode($doc, $doi, $url)
    {
        $deployment = $this->getDeployment();
        $doiDataNode = $doc->createElementNS($deployment->getNamespace(), 'doi_data');
        $doiDataNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'doi', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        $doiDataNode->appendChild($doc->createElementNS($deployment->getNamespace(), 'resource', htmlspecialchars($url, ENT_COMPAT, 'UTF-8')));
        return $doiDataNode;
    }

    /**
     * Create relationships to the parent/VOR DOI (versioning) and to cited data citations
     */
    public function appendRelationships(DOMDocument $doc, DOMElement $parentNode, string $parentDoi, string $vorDoi, Publication $publication): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        $relationsDataNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:program');
        $relationsDataNode->setAttribute('name', 'relations');

        if ($parentDoi) {
            $relationsDataNode->appendChild($this->createParentDoiNode($doc, $parentDoi));
        }
        if ($vorDoi) {
            $relationsDataNode->appendChild($this->createVorDoiNode($doc, $vorDoi));
        }

        foreach ($publication->getData('dataCitations') ?? [] as $dataCitation) {
            $relatedItemNode = $this->createDataCitationRelatedItemNode($doc, $dataCitation);
            if ($relatedItemNode) {
                $relationsDataNode->appendChild($relatedItemNode);
            }
        }

        if ($relationsDataNode->hasChildNodes()) {
            $parentNode->appendChild($relationsDataNode);
        }
    }

    /**
     * Create a rel:related_item node for a data citation
     */
    public function createDataCitationRelatedItemNode(DOMDocument $doc, DataCitation $dataCitation): ?DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();

        // Only include data citations that have an identifier (DOI, URL, etc.) and a relationship type
        if (($identifier = $dataCitation->getAttribute('identifier')) && ($identifierType = $dataCitation->getAttribute('identifierType'))) {
            $identifierType = strtolower($identifierType);
        } elseif ($identifier = $dataCitation->getAttribute('url')) {
            $identifierType = 'uri';
        } else {
            return null;
        }

        // Mapping based on JATS4R recommendation (https://jats4r.niso.org/data-citations)
        $relationshipTypeMapping = [
            'supporting' => 'references',
            'generated' => 'isSupplementedBy',
            'analyzed' => 'references',
            'non-analyzed' => 'references',
        ];
        $relationshipType = $relationshipTypeMapping[$dataCitation->getAttribute('relationshipType')] ?? 'references';

        $relatedItemNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:related_item');
        if ($title = $dataCitation->getAttribute('title')) {
            $descriptionNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:description', htmlspecialchars($title, ENT_COMPAT, 'UTF-8'));
            $relatedItemNode->appendChild($descriptionNode);
        }
        $interWorkRel = $doc->createElementNS($deployment->getRELNamespace(), 'rel:inter_work_relation', htmlspecialchars($identifier, ENT_COMPAT, 'UTF-8'));
        $interWorkRel->setAttribute('relationship-type', $relationshipType);
        $interWorkRel->setAttribute('identifier-type', $identifierType);
        $relatedItemNode->appendChild($interWorkRel);

        return $relatedItemNode;
    }

    /**
     * Create and return the parent DOI relation node.
     *
     * @param \DOMDocument $doc
     * @param string $parentDoi
     *
     * @return \DOMElement
     */
    public function createParentDoiNode($doc, $parentDoi)
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $parentDoiNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:related_item');
        $intraWorkRelationNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:intra_work_relation', htmlspecialchars($parentDoi, ENT_COMPAT, 'UTF-8'));
        $intraWorkRelationNode->setAttribute('relationship-type', 'isVersionOf');
        $intraWorkRelationNode->setAttribute('identifier-type', 'doi');
        $parentDoiNode->appendChild($intraWorkRelationNode);

        return $parentDoiNode;
    }

    /**
     * Create and return the VOR DOI relation node.
     *
     * @param \DOMDocument $doc
     * @param string $vorDoi
     *
     * @return \DOMElement
     */
    public function createVorDoiNode($doc, $vorDoi)
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $vorDoiNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:related_item');
        $intraWorkRelationNode = $doc->createElementNS($deployment->getRELNamespace(), 'rel:intra_work_relation', htmlspecialchars($vorDoi, ENT_COMPAT, 'UTF-8'));
        $intraWorkRelationNode->setAttribute('relationship-type', 'isPreprintOf');
        $intraWorkRelationNode->setAttribute('identifier-type', 'doi');
        $vorDoiNode->appendChild($intraWorkRelationNode);

        return $vorDoiNode;
    }

    /**
     * Helper to ensure dispatcher is available even when called from CLI tools
     *
     *
     */
    protected function _getDispatcher(Request $request): Dispatcher
    {
        $dispatcher = $request->getDispatcher();
        if ($dispatcher === null) {
            $dispatcher = Application::get()->getDispatcher();
        }

        return $dispatcher;
    }

    /**
     * Append stored HTML (e.g. a publication title) to a node as Crossref face markup.
     *
     * The inline tags Crossref accepts (b, i, u, sup, sub) are kept as elements,
     * strong and em become b and i, any other tag is dropped and its text kept.
     * Text is decoded by the HTML parser and escaped once when the document is written.
     */
    public function appendFaceMarkup(DOMDocument $doc, DOMElement $parent, string $html): void
    {
        if ($html === '') {
            return;
        }
        $source = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $source->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $body = $source->getElementsByTagName('body')->item(0);
        if ($body) {
            $this->copyFaceMarkup($doc, $parent, $body);
        }
    }

    /**
     * Copy the children of an HTML node into a Crossref node, see appendFaceMarkup().
     */
    private function copyFaceMarkup(DOMDocument $doc, DOMElement $parent, \DOMNode $from): void
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $faces = ['b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 'sup' => 'sup', 'sub' => 'sub'];
        foreach ($from->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $parent->appendChild($doc->createTextNode($child->nodeValue));
            } elseif ($child instanceof DOMElement) {
                $face = $faces[strtolower($child->nodeName)] ?? null;
                if ($face) {
                    $parent->appendChild($node = $doc->createElementNS($deployment->getNamespace(), $face));
                    $this->copyFaceMarkup($doc, $node, $child);
                } else {
                    $this->copyFaceMarkup($doc, $parent, $child);
                }
            }
        }
    }
}
