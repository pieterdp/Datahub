<?php

namespace DataHub\ResourceAPIBundle\Controller;

use FOS\RestBundle\Controller\Annotations\Get;
use FOS\RestBundle\Controller\Annotations\Post;
use FOS\RestBundle\Controller\Annotations\Put;
use FOS\RestBundle\Controller\Annotations\Delete;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\View;

use FOS\RestBundle\Request\ParamFetcherInterface;

use Nelmio\ApiDocBundle\Annotation\ApiDoc;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

use DataHub\ResourceAPIBundle\Document\Record;
use DataHub\ResourceAPIBundle\Repository\RecordRepository;

use Hateoas\HateoasBuilder;
use Hateoas\Representation\CollectionRepresentation;
use Hateoas\Representation\OffsetRepresentation;

use JMS\Serializer\SerializationContext;

/**
 * REST controller for Data.
 *
 * @author  Kalman Olah <kalman@inuits.eu>
 * @author Matthias Vandermaesen <matthias.vandermaesen@vlaamsekunstcollectie.be>
 *
 * @todo This class needs heavy refactoring. There are several things to be done:
 *  - Full Doctrine ODM support to manage the I/O with mongo instead of acustom
 *    service class.
 *  - Use ParamConverters to convert the incoming XML to JSON encoded string and
 *    inject both representations into a simple Document Model.
 *  - Use a proper view handler to switch between JSON and XML variant.
 *
 * @package DataHub\ResourceAPIBundle
 */
class DataController extends Controller
{
    /**
     * List records.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *       200 = "Returned when successful"
     *     }
     * )
     *
     * @Get("/data")
     *
     * @QueryParam(name="offset", requirements="\d+", nullable=true, description="Offset from which to start listing entries.")
     * @QueryParam(name="limit", requirements="\d{1,2}", default="5", description="How many entries to return.")
     * @QueryParam(name="sort", requirements="[a-zA-Z\.]+,(asc|desc|ASC|DESC)", nullable=true, description="Sorting field and direction.")
     *
     * @View(
     *     serializerGroups={"list"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     *
     * @return array<mixed>
     */
    public function cgetDatasAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        // get parameters
        $offset = intval($paramFetcher->get('offset'));
        $limit = intval($paramFetcher->get('limit'));
        // @todo
        //   Remove sorting, not relevant here

        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $records = $recordRepository->findBy(array(), null, $limit, $offset);
        $total = $recordRepository->count();

        $offsetCollection = new OffsetRepresentation(
            new CollectionRepresentation(
                $records, 'records', 'records'
            ),
            'get_datas',
            array(),
            $offset,
            $limit,
            $total
        );

        $context = SerializationContext::create()->setGroups(array('Default','json'));
        $json = $this->get('serializer')->serialize($offsetCollection, 'json', $context);

        return new Response($json, Response::HTTP_OK, array('Content-Type' => 'application/hal+json'));
    }

    /**
     * Get a single record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         200 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @Get("/data/{id}", requirements={"id" = ".+?"})
     *
     * @View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param string $id Data ID of entry to return
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function getDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $id);

        if (!$record) {
            throw new NotFoundHttpException('Record could not be found');
        }

        // Circumventing the XML serializer here, since we already have the
        // raw XML input from the store.
        if ($request->getRequestFormat() == 'xml') {
            return new Response($record->getRaw(), Response::HTTP_OK, array('Content-Type' => 'application/xml'));
        }

        $hateoas = HateoasBuilder::create()->build();
        $context = SerializationContext::create()->setGroups(array('json'));
        $json = $hateoas->serialize($record, 'json', $context);

        return new Response($json, Response::HTTP_OK, array('Content-Type' => 'application/hal+json'));
    }

    /**
     * Create a record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         201 = "Returned when successful",
     *         400 = "Returned if the form could not be validated, or record already exists",
     *     }
     * )
     *
     * @View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true,
     *     statusCode=201
     * )
     *
     * @Post("/data")
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @return mixed
     * @throws \Exception
     */
    public function postDataAction(ParamFetcherInterface $paramFetcher, Request $request)
    {
        $data = $request->request->all();

        if (empty($data)) {
            throw new UnprocessableEntityHttpException('No record was provided.');
        }

        // Fetch the datatype from the converter
        $factory = $this->get('datahub.resource.builder.converter.factory');
        $dataType = $factory->getConverter()->getDataType();

        // Get the (p)id's
        $dataPids = $dataType->getRecordId($data);
        $objectPids = $dataType->getObjectId($data);

        // Get the JSON & XML Raw variants of the record
        $variantJSON = json_encode($data);
        $variantXML = $request->getContent();

        // Fetch a dataPid. This will be the ID used in the database for this
        // record.
        // @todo Differentiate between 'preferred' and 'alternate' dataPids
        $dataPid = $dataPids[0];

        // Check whether record already exists
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $dataPid);
        if ($record instanceof Record) {
            throw new ConflictHttpException('Record with this ID already exists.');
        }

        $documentManager = $this->get('doctrine_mongodb')->getManager();

        $record = new Record();
        $record->setRecordIds($dataPids);
        $record->setObjectIds($objectPids);
        $record->setRaw($variantXML);
        $record->setJson($variantJSON);

        $documentManager->persist($record);
        $documentManager->flush();

        $id = $record->getId();

        if (!$id) {
            throw new BadRequestHttpException('Could not store new record.');
        } else {
            $response = Response::HTTP_CREATED;
            $headers = [
                'Location' => $request->getPathInfo() . '/' . urlencode($dataPid)
            ];
        }

        return new Response('', $response, $headers);
    }

    /**
     * Update a record (replaces the entire resource).
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     input = "DataHub\ResourceAPIBundle\Form\Type\DataFormType",
     *     statusCodes = {
     *         201 = "Returned when a record was succesfully created",
     *         204 = "Returned when an existing recurd was succesfully updated",
     *         400 = "Returned if the record could not be stored or parsed",
     *     }
     * )
     *
     * @View(
     *     serializerGroups={"single"},
     *     serializerEnableMaxDepthChecks=true
     * )
     *
     * @Put("/data/{id}", requirements={"id" = ".+?"})
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param integer $id ID of entry to update
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function putDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        // Get a decoded record
        $record = $request->request->all();

        if (empty($record)) {
            throw new UnprocessableEntityHttpException('No record was provided.');
        }

        // Fetch the datatype from the converter
        $factory = $this->get('datahub.resource.builder.converter.factory');
        $dataType = $factory->getConverter()->getDataType();

        // Get the (p)id's
        $recordIds = $dataType->getRecordId($record);
        $objectIds = $dataType->getObjectId($record);

        // Get the JSON & XML Raw variants of the record
        $variantJSON = json_encode($record);
        $variantXML = $request->getContent();

        $documentManager = $this->get('doctrine_mongodb')->getManager();
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $id);

        // If the record does not exist, create it, if it does exist, update the existing record.
        // The ID of a particular resource is not generated server side, but determined client side.
        // The ID is the resource URI to which the PUT request was made.
        //
        //   See: https://www.w3.org/Protocols/rfc2616/rfc2616-sec9.html#sec9.6
        //
        if (!$record instanceof Record) {
            $record = new Record();
            $record->setRecordIds($recordIds);
            $record->setObjectIds($objectIds);
            $record->setRaw($variantXML);
            $record->setJson($variantJSON);

            $documentManager->persist($record);
            $documentManager->flush();

            $id = $record->getId();

            if (!$id) {
                throw new BadRequestHttpException('Could not store new record.');
            } else {
                $response = Response::HTTP_CREATED;
                $headers = [];
            }
        } else {
            $record->setRecordIds($recordIds);
            $record->setObjectIds($objectIds);
            $record->setRaw($variantXML);
            $record->setJson($variantJSON);

            $documentManager->flush();

            $id = $record->getId();

            if (!$id) {
                throw new BadRequestHttpException('Could not store updated record.');
            } else {
                $response = Response::HTTP_NO_CONTENT;
                $headers = [];
            }
        }

        return new Response('', $response, $headers);
    }

    /**
     * Delete a record.
     *
     * @ApiDoc(
     *     section = "DataHub",
     *     resource = true,
     *     statusCodes = {
     *         204 = "Returned when successful",
     *         404 = "Returned if the resource was not found"
     *     }
     * )
     *
     * @View(statusCode="204")
     *
     * @Delete("/data/{id}", requirements={"id" = ".+?"})
     *
     * @param ParamFetcherInterface $paramFetcher param fetcher service
     * @param Request $request the request object
     * @param integer $id ID of entry to delete
     *
     * @return mixed
     *
     * @throws NotFoundHttpException if the resource was not found
     */
    public function deleteDataAction(ParamFetcherInterface $paramFetcher, Request $request, $id)
    {
        // Check whether record already exists
        $recordRepository = $this->get('datahub.resource_api.repository.default');
        $record = $recordRepository->findOneByProperty('recordIds', $id);

        if (!$record instanceof Record) {
            throw new NotFoundHttpException('Record could not be found.');
        }

        $documentManager = $this->get('doctrine_mongodb')->getManager();

        $documentManager->remove($record);
        $documentManager->flush();
    }
}
