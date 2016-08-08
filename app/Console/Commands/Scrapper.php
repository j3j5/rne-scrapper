<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Sunra\PhpSimple\HtmlDomParser;
use marcushat\RollingCurlX;
use Log;
use Carbon\Carbon;

class Scrapper extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "scrapper:rne
                        {show : The slug of the show to be scrapped}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape the RNE page for all the URLs of a given show.';

    private $base_url;
    private $table_url;
    private $show_id;
    private $last_page;
    private $export_file;
    private $export_fields;
    private $items_per_page;


    public function __construct()
    {
        parent::__construct();

        $this->base_url = "http://www.rtve.es/alacarta/audios/";
        $this->table_url = "http://www.rtve.es/alacarta/interno/contenttable.shtml?orderCriteria=DESC&modl=TOC&locale=es&advSearchOpen=false";
        $this->items_per_page = 15;
        $this->shows_info = [];
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $arguments = $this->argument();
        $show = $arguments['show'];

        $this->export_file = storage_path("app/$show-" . date('Y-m-d') .".csv");
        $show = str_slug($show);

        $dom = HtmlDomParser::file_get_html($this->base_url.$show);

        $this->extractGeneralShowInfo($dom);

        $this->info("Starting the scrapping");
        $this->output->progressStart($this->last_page + 1);

        $rolling_curl = new RollingCurlX($this->items_per_page);
        $info = [];
        for ($current_page = 1; $current_page <= $this->last_page; $current_page++) {
            $page_url = $this->table_url . '&' . http_build_query(['ctx' => $this->show_id, 'pbq' => $current_page, 'pageSize' => $this->items_per_page]);
            $rolling_curl->addRequest($page_url, [], [$this, 'callback']);
        }
        $rolling_curl->execute();
        $this->output->progressFinish();

        $this->info("Sort it by date");
        usort($this->shows_info, function ($a, $b) {
            return strtotime($a[$this->date_position]) - strtotime($b[$this->date_position]);
        });
        $this->info("Done");

        $this->info("Starting the export");
        $this->exportToCSV($this->shows_info);
        $this->info("Done! You can find your file at {$this->export_file}");
    }

    /**
     * Callback from the parallel requests to extract all info page
     */
    public function callback($response, $url, $request_info, $user_data, $time) {

        if ($request_info['http_code'] !== 200) {
            $this->error("Fetch error {$request_info['http_code']} for '$url'");
            return;
        }

        $page_dom = HtmlDomParser::str_get_html($response);
        $this->shows_info = array_merge($this->extractShowInfoFromPage($page_dom), $this->shows_info);
        $this->output->progressAdvance(1);
    }

    /**
     * Export all the info to a CSV file
     */
    public function exportToCSV($info)
    {
        $fp = fopen($this->export_file, 'w');
        fputcsv($fp, $this->export_fields);
        $this->output->progressStart(count($info));
        foreach ($info as $fields) {
            fputcsv($fp, $fields);
            $this->output->progressAdvance(1);
        }
        fclose($fp);
        $this->output->progressFinish();
    }

    /**
     * Get general info about the show (amount of pages available, show_id...)
     */
    private function extractGeneralShowInfo($dom) {
        $last_page_selector = 'li[class=ultimo] a';
        $last_page_path = $dom->find($last_page_selector, 0)->href;
        $query = parse_url(html_entity_decode($last_page_path), PHP_URL_QUERY);
        parse_str($query, $query_var) ;

        if(!isset($query_var['ctx'], $query_var['pbq'])) {
            $this->error("The info could not be extracted. Is it the proper page?");
            if(config('app.debug')) {
                $this->line(print_r($query, true));
            }
            exit;
        }

        $this->show_id = $query_var['ctx'];
        $this->last_page = $query_var['pbq'];
    }

    /**
     * Get the info actual info from the DOM page.
     */
    private function extractShowInfoFromPage($dom)
    {
        $info = [];
        $this->export_fields = [];
        $table_selector = "div.ContentTabla ul";
        $ul = $dom->find($table_selector, 0);

        $first = true;
        $i = 0;
        $valid = ['col_tit', 'col_tip', 'col_dur', 'col_pop', 'col_fec'];
        foreach ($ul->find('li') as $li) {
            // Extract the headers
            if ($first) {
                foreach($li->find('span span') as $header) {
                    $this->export_fields[] = trim(strip_tags($header->innertext));
                }
                $this->date_position = array_search('Fecha', $this->export_fields);
                $this->export_fields[] = 'Link';
                $first = false;
            } else {
                // Extract the content
                foreach($li->find('span') as $span) {
                    switch($span->class) {
                        case 'col_tip': //
                            if (!empty($span->find('span', 1))) {
                                $info[$i][] = $span->find('span', 1)->innertext;
                            } else {
                                $info[$i][] = $span->find('span', 0)->innertext;
                            }
                            if (!empty($span->find('a', 0))) {
                                $link = $span->find('a', 0)->href;
                            }
                            break;
                        case 'col_pop': //
                            $info[$i][] = trim(html_entity_decode(strip_tags($span->find('span', 0)->title)));
                            break;
                        case 'col_fec': // fecha
                            $info[$i][] = $this->parseDate(trim(html_entity_decode(strip_tags($span->innertext))));
                            break;
                        case 'col_tit': // título
                        case 'col_dur': // duración
                            $info[$i][] = trim(html_entity_decode(strip_tags($span->innertext)));
                            break;
                        default:
                            break;
                    }
                }
                // Add the link
                if (!empty($link)) {
                    $info[$i][] = $link;
                    $link = '';
                }
                $i++;
            }
        }
        return $info;
    }

    /**
     * Try to parse the date in spanish to a more "orderable" date
     */
    private function parseDate($spanish_date)
    {
        $date_format = "Y/m/d";
        $original_locale = setlocale(LC_TIME, 0);
        setlocale(LC_TIME, 'es_ES');
        $ts = strptime($spanish_date, '%d %b %Y');
        $date = $spanish_date;
        if (!empty($ts)) {
            $date = Carbon::create(($ts['tm_year'] + 1900), ($ts['tm_mon'] + 1), $ts['tm_mday'], $ts['tm_hour'], $ts['tm_min'], $ts['tm_sec'])->format($date_format);
        } else {
            // try to translate
            $dates = [
                'lunes' => 'monday', 'martes' => 'tuesday',
                'miércoles' => 'wednesday', 'jueves' => 'thursday',
                'viernes' => 'friday', 'sábado' => 'saturday', 'domingo' => 'sunday'
            ];
            $string_copy = strtolower($spanish_date);   // Go lowercase
            $string_copy = str_replace('pasado', 'last', $string_copy);
            $string_copy = str_replace('hoy', 'today', $string_copy);
            $string_copy = str_replace('ayer', 'yesterday', $string_copy);
            foreach (array_keys($dates) as $name) {
                $string_copy = str_replace($name, $dates[$name], $string_copy);
            }
            // Did it work?
            $new_date = Carbon::parse($string_copy);
            if (!empty($new_date)) {
                $date = $new_date->format($date_format);
            }
        }
        setlocale(LC_TIME, $original_locale);
        return $date;
    }
}
