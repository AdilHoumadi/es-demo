<?php
require_once 'vendor/autoload.php';

$action = $_GET['action'] ?? '';

$esClient = new \Elastica\Client([
    'host' => 'elasticsearch',
    'port' => 9200,
    'username' => 'elastic',
    'password' => 'changeme',
]);
const INDEX = 'hireme';
const TYPE = 'profile';

switch ($action) {

    case 'init':
        $esIndex = $esClient->getIndex(INDEX);
        $esIndex->create(
            [
                'number_of_shards' => 4,
                'number_of_replicas' => 1,
                'analysis' => [
                    'analyzer' => [
                        'default' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['lowercase', 'mySnowball']
                        ],
                        'default_search' => [
                            'type' => 'custom',
                            'tokenizer' => 'standard',
                            'filter' => ['standard', 'lowercase', 'mySnowball']
                        ]
                    ],
                    'filter' => [
                        'mySnowball' => [
                            'type' => 'snowball',
                            'language' => 'English'
                        ]
                    ]
                ]
            ],
            true
        );
        $esType = $esIndex->getType(TYPE);
        $mapping = new \Elastica\Type\Mapping();
        $mapping->setType($esType);
        $mapping->setProperties([
            'id' => ['type' => 'string', 'include_in_all' => false],
            'first_name' => ['type' => 'string', 'include_in_all' => false],
            'last_name' => ['type' => 'string', 'include_in_all' => false],
            'email' => ['type' => 'string', 'include_in_all' => false],
            'skills' => ['type' => 'string', 'include_in_all' => false],
            'distance' => ['type' => 'double', 'include_in_all' => false],
            'location' => ['type' => 'geo_point', 'doc_values' => true, 'include_in_all' => false],
        ]);
        $mapping->send();
        echo 'Index and type created!';
        break;

    case 'demo':
        $location = include_once 'location.php';
        $faker = Faker\Factory::create();
        $faker->addProvider(new \Alister\Faker\Provider\Skills($faker));
        $faker->seed(1);
        define('MAX', count($location));
        $profiles = [];

        for ($i = 0; $i < MAX; $i++) {
            $id = $faker->unique()->uuid;
            $profiles[] = new \Elastica\Document($id, [
                'id' => $id,
                'first_name' => $faker->firstName,
                'last_name' => $faker->lastName,
                'email' => $faker->email,
                'distance' => $faker->numberBetween(10, 100),
                'location' => [
                    'lat' => $location[$i]['latitude'],
                    'lon' => $location[$i]['longitude']
                ],
                'skills' => $faker->skills($faker->numberBetween(5, 10))
            ]);
        }
        $esIndex = $esClient->getIndex(INDEX);
        $esType = $esIndex->getType(TYPE);
        $esType->addDocuments($profiles);
        $esType->getIndex()->refresh();
        echo 'Demo data generated!';
        break;

    case 'search':

        $jobLocation = [
            'lat' => 50.8503463,
            'lon' => 4.351721099999963
        ];
        $jobSkills = [
            [
                'name' => 'rest',
                'score' => 100
            ],
            [
                'name' => 'scala',
                'score' => 75
            ],
            [
                'name' => 'zend',
                'score' => 50
            ],
            [
                'name' => 'xcode',
                'score' => 25
            ],
        ];

        $boolQuery = new \Elastica\Query\BoolQuery();
        $search = new \Elastica\Search($esClient);
        $search->addIndex(INDEX)->addType(TYPE);
        $script = new \Elastica\Query\Script([
            'script' => [
                'lang' => 'painless',
                'inline' => "doc['location'].arcDistance(params.lat, params.lon) * 0.001 <= doc['distance'].value",
                'params' => $jobLocation
            ]
        ]);
        $function = new \Elastica\Query\FunctionScore();
        $function->setQuery(new \Elastica\Query\MatchAll());
        $function->setScoreMode(\Elastica\Query\FunctionScore::BOOST_MODE_SUM);
        $avg = array_sum(array_map(function ($elt) {
            return $elt['score'];
        }, $jobSkills));
        $function->setMinScore($avg * 0.75);
        foreach ($jobSkills as $jobSkill) {
            $function->addWeightFunction(
                $jobSkill['score'],
                new \Elastica\Query\Match('skills', $jobSkill['name'])
            );
        }
        $boolQuery->addMust([$script, $function]);
        $search
            ->setQuery($boolQuery)
            ->getQuery()
            ->setFrom(0)
            ->setSize(2000);
        ?>

        <?php echo 'Location: Brussels (' . $jobLocation['lat'] . ', ' . $jobLocation['lon'] . ') <br>' ?>
        <?php echo 'Skills: <br>' ?>
        <?php
        array_map(function ($elt) {
            echo $elt['name'] . ': ' . $elt['score'] . '<br>';
        }, $jobSkills)
        ?>
        <br>
        <table border="1" cellpadding="2" cellspacing="1" width="100%">
            <tr>
                <th width="20%">Id</th>
                <th width="5%">Nom</th>
                <th width="5%">Prenom</th>
                <th width="20%">Location</th>
                <th width="5%">Distance</th>
                <th width="20%">Skills</th>
            </tr>
            <?php foreach ($search->search()->getDocuments() as $document): ?>
                <?php $data = $document->getData(); ?>
                <tr>
                    <td><?php echo($data['id']) ?></td>
                    <td><?php echo($data['first_name']) ?></td>
                    <td><?php echo($data['last_name']) ?></td>
                    <td><?php echo $data['location']['lat'],', ',$data['location']['lon'] ?></td>
                    <td><?php echo($data['distance']) ?>Km</td>
                    <td><?php echo join(', ', $data['skills']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php
        break;

}

