<?php namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Sunra\PhpSimple\HtmlDomParser;
use Log;

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


    public function __construct()
    {
        parent::__construct();

        $this->base_url = "http://www.rtve.es/alacarta/audios/";
        $this->table_url = "http://www.rtve.es/alacarta/interno/contenttable.shtml?orderCriteria=DESC&modl=TOC&locale=es&pageSize=15&advSearchOpen=false";
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
        $this->output->progressStart($this->last_page);


        $info = [];
        for ($current_page = 1; $current_page <= $this->last_page; $current_page++) {
            $this->output->progressAdvance(1);
            $page_url = $this->table_url . '&' . http_build_query(['ctx' => $this->show_id, 'pbq' => $current_page]);
            $page_dom = HtmlDomParser::file_get_html($page_url);
            $info = array_merge($this->extractShowInfoFromPage($page_dom), $info);
        }
        $this->output->progressFinish();

        $this->info("Starting the export");
        $this->exportToCSV($info);
        $this->info("Done! You can find your file at {$this->export_file}");
    }

    public function exportToCSV($info)
    {
        $fp = fopen($this->export_file, 'w');
        fputcsv($fp, $this->export_fields);
        $this->output->progressStart(count($info));
        foreach ($info as $fields) {
            $this->output->progressAdvance(1);
            fputcsv($fp, $fields);
        }
        fclose($fp);
        $this->output->progressFinish();
    }

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
                $this->export_fields[] = 'Link';
                $first = false;
            } else {
                // Extract the content
                foreach($li->find('span') as $span) {
                    switch($span->class) {
                        case 'col_tip':
                            if (!empty($span->find('span', 1))) {
                                $info[$i][] = $span->find('span', 1)->innertext;
                            } else {
                                $info[$i][] = $span->find('span', 0)->innertext;
                            }
                            if (!empty($span->find('a', 0))) {
                                $link = $span->find('a', 0)->href;
                            }
                            break;
                        case 'col_pop':
                            $info[$i][] = trim(strip_tags($span->find('span', 0)->title));
                            break;
                        case 'col_tit':
                        case 'col_fec':
                        case 'col_dur':
                            $info[$i][] = trim(strip_tags($span->innertext));
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
}
