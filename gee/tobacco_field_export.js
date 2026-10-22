/**
 * TOBACCO FIELD EXPORT — Mashonaland West, Zimbabwe
 * Zimgeo (Map Tech Pvt Ltd)
 *
 * Purpose: run the tobacco classification once per season and export
 * field-level results (one row per detected field) for the web dashboard.
 *
 * SETUP:
 * 1. Keep 'tobacco' and 'non_tobacco' FeatureCollections as script Imports
 *    (same as the app script).
 * 2. Set SEASON, START_DATE and END_DATE below.
 * 3. Run, then go to the Tasks tab and click RUN on the three export tasks.
 * 4. Download the CSVs from Google Drive (folder: Tobacco_Exports) and upload
 *    them on the portal: Administration → Upload data (timb.zimgeo.co.zw).
 *    Tip: zip the Fields CSV first — it uploads about 5x faster.
 */

// ============================================================
// 0. CONFIG
// ============================================================
var SEASON     = '2025-26';
var START_DATE = '2025-12-01';
var END_DATE   = '2026-02-15';

var mashWest = ee.FeatureCollection('users/amazhindu/MashWest');
var region = mashWest.geometry();

// District boundaries. GAUL is used by default; replace with your own
// district asset if you have newer boundaries (keep a numeric code field
// and a name field and update the two property names below).
var districts = ee.FeatureCollection('FAO/GAUL/2015/level2')
    .filter(ee.Filter.eq('ADM0_NAME', 'Zimbabwe'))
    .filterBounds(region);
var DISTRICT_CODE_FIELD = 'ADM2_CODE';
var DISTRICT_NAME_FIELD = 'ADM2_NAME';
var PROVINCE_NAME       = 'Mashonaland West';

var CLOUD_FILTER       = 60;
var CLD_PRB_THRESH     = 40;
var AVG_YIELD_T_PER_HA = 2.2;   // benchmark; update as TIMB publishes figures
var MIN_FIELD_PIXELS   = 10;    // 10 px x 100 m2 = 0.1 ha minimum field size
var SIMPLIFY_M         = 5;     // geometry simplification tolerance (m)
var predictionBands = ['B2', 'B3', 'B4', 'B8', 'B11', 'B5', 'NDVI'];

// ============================================================
// 1. SENTINEL-2 COMPOSITE (same logic as the app)
// ============================================================
var s2Sr = ee.ImageCollection('COPERNICUS/S2_SR_HARMONIZED')
    .filterBounds(region)
    .filterDate(START_DATE, END_DATE)
    .filter(ee.Filter.lt('CLOUDY_PIXEL_PERCENTAGE', CLOUD_FILTER));

var s2Clouds = ee.ImageCollection('COPERNICUS/S2_CLOUD_PROBABILITY')
    .filterBounds(region)
    .filterDate(START_DATE, END_DATE);

var s2SrWithCloud = ee.ImageCollection(ee.Join.saveFirst('cloud_mask').apply({
  primary: s2Sr,
  secondary: s2Clouds,
  condition: ee.Filter.equals({leftField: 'system:index', rightField: 'system:index'})
}));

function prepS2(image) {
  var cloudProb = ee.Image(image.get('cloud_mask')).select('probability');
  var scl = image.select('SCL');
  var sclMask = scl.neq(3).and(scl.neq(8)).and(scl.neq(9))
                   .and(scl.neq(10)).and(scl.neq(11));
  var mask = sclMask.and(cloudProb.lt(CLD_PRB_THRESH));
  var scaled = image.updateMask(mask).divide(10000);
  var ndvi = scaled.normalizedDifference(['B8', 'B4']).rename('NDVI');
  return scaled.addBands(ndvi).copyProperties(image, ['system:time_start']);
}

var composite = s2SrWithCloud.map(prepS2).median().clip(region);

// ============================================================
// 2. CLASSIFICATION (same logic as the app)
// ============================================================
var sampled = composite.select(predictionBands).sampleRegions({
  collection: tobacco.merge(non_tobacco),
  properties: ['class'],
  scale: 10,
  tileScale: 4
}).randomColumn('random', 42);

var trainSet = sampled.filter(ee.Filter.lt('random', 0.7));
var testSet  = sampled.filter(ee.Filter.gte('random', 0.7));

var classifier = ee.Classifier.smileRandomForest(100).train({
  features: trainSet,
  classProperty: 'class',
  inputProperties: predictionBands
});

var accuracy = testSet.classify(classifier)
    .errorMatrix('class', 'classification').accuracy();

var classified = composite.select(predictionBands).classify(classifier);

// ============================================================
// 3. CLEAN MASK -> FIELD POLYGONS
// ============================================================
// Smooth salt-and-pepper noise, then drop clumps smaller than the minimum size
var tobaccoMask = classified.eq(1).focalMode(1).selfMask();
var clumpSize = tobaccoMask.connectedPixelCount(MIN_FIELD_PIXELS * 2, false);
var tobaccoClean = tobaccoMask.updateMask(clumpSize.gte(MIN_FIELD_PIXELS)).toInt();

// District code raster, carried into each polygon via mode
var districtImg = districts
    .reduceToImage([DISTRICT_CODE_FIELD], ee.Reducer.first())
    .rename('district_code').toInt();

var fields = tobaccoClean.addBands(districtImg).reduceToVectors({
  geometry: region,
  scale: 10,
  geometryType: 'polygon',
  eightConnected: false,
  labelProperty: 'tobacco',
  reducer: ee.Reducer.mode(),
  maxPixels: 1e13,
  tileScale: 4
});

// Mean NDVI per field (useful for a future NDVI-based yield model)
fields = composite.select('NDVI').reduceRegions({
  collection: fields,
  reducer: ee.Reducer.mean().setOutputs(['ndvi_mean']),
  scale: 10,
  tileScale: 4
});

// District code -> name lookup
var districtLookup = ee.Dictionary.fromLists(
  districts.aggregate_array(DISTRICT_CODE_FIELD).map(function(c) {
    return ee.Number(c).format('%d');
  }),
  districts.aggregate_array(DISTRICT_NAME_FIELD)
);

fields = fields.map(function(f) {
  var geom = f.geometry().simplify(SIMPLIFY_M);
  var areaHa = geom.area(1).divide(10000);
  var code = ee.Algorithms.If(f.get('mode'),
      ee.Number(f.get('mode')).format('%d'), 'NA');
  var centroid = geom.centroid(1).coordinates();
  return ee.Feature(geom, {
    season: SEASON,
    start_date: START_DATE,
    end_date: END_DATE,
    province: PROVINCE_NAME,
    district: districtLookup.get(code, 'Unknown'),
    area_ha: areaHa,
    est_yield_t: areaHa.multiply(AVG_YIELD_T_PER_HA),
    yield_benchmark_t_ha: AVG_YIELD_T_PER_HA,
    ndvi_mean: f.get('ndvi_mean'),
    lon: centroid.get(0),
    lat: centroid.get(1)
  });
});

// ============================================================
// 4. EXPORTS
// ============================================================
// 4a. Field-level table (main dashboard input)
Export.table.toDrive({
  collection: fields,
  description: 'Tobacco_Fields_MashWest_' + SEASON.replace('-', '_'),
  folder: 'Tobacco_Exports',
  fileFormat: 'CSV',
  selectors: ['season', 'start_date', 'end_date', 'province', 'district',
              'area_ha', 'est_yield_t', 'yield_benchmark_t_ha',
              'ndvi_mean', 'lon', 'lat', '.geo']
});

// 4b. Run metadata (accuracy, image count, dates)
var runInfo = ee.FeatureCollection([ee.Feature(null, {
  season: SEASON,
  start_date: START_DATE,
  end_date: END_DATE,
  province: PROVINCE_NAME,
  classifier_accuracy: accuracy,
  images_used: s2Sr.size(),
  training_points: sampled.size(),
  min_field_ha: MIN_FIELD_PIXELS * 100 / 10000,
  yield_benchmark_t_ha: AVG_YIELD_T_PER_HA
})]);

Export.table.toDrive({
  collection: runInfo,
  description: 'Tobacco_RunInfo_MashWest_' + SEASON.replace('-', '_'),
  folder: 'Tobacco_Exports',
  fileFormat: 'CSV'
});

// 4c. Training / reference points (shown as tobacco / non-tobacco dots on the map)
var trainingPts = tobacco.merge(non_tobacco).map(function(f) {
  var c = f.geometry().centroid(1).coordinates();
  return ee.Feature(f.geometry().centroid(1), {
    'class': f.get('class'), lon: c.get(0), lat: c.get(1), season: SEASON
  });
});

Export.table.toDrive({
  collection: trainingPts,
  description: 'Tobacco_TrainingPoints_MashWest_' + SEASON.replace('-', '_'),
  folder: 'Tobacco_Exports',
  fileFormat: 'CSV',
  selectors: ['season', 'class', 'lon', 'lat']
});

// ============================================================
// 5. QUICK VISUAL CHECK
// ============================================================
Map.centerObject(mashWest, 9);
Map.addLayer(composite, {bands: ['B4', 'B3', 'B2'], min: 0.02, max: 0.25}, 'Composite', false);
Map.addLayer(tobaccoClean, {palette: ['00aa00']}, 'Tobacco (cleaned)');
Map.addLayer(districts.style({color: 'ffffff', fillColor: '00000000', width: 1}), {}, 'Districts');
Map.addLayer(mashWest.style({color: 'FFD700', fillColor: '00000000', width: 2}), {}, 'Province');
print('Classifier accuracy:', accuracy);
print('Images used:', s2Sr.size());
