<?php
include("../index/header.php");
include("../index/menu.php");
$objProduct = new App\product\product();


$column = "campaign_name,subject,email_to,email_cc,email_bcc,pre_txt,email_body,file_name,schedule_date,sender_id";
$page_heading = "SCHEDULE CAMPAIGN";
$table = 'email_schedule';

function getVehicleDetailFromApi($regNo){
    $url = "https://www.carcheck.co.uk/volkswagen/$regNo";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);
    
    // Load the HTML
    $dom = new DOMDocument;
    @$dom->loadHTML($output); // Suppress warnings with @ since HTML might not be well-formed
    
    // Use XPath to query for all divs with the class "row"
    $xpath = new DOMXPath($dom);
    $car_sections = $xpath->query("//div[contains(@class, 'row')]");
    
    // Initialize an array to hold all key-value pairs from all rows
    $all_data = [];
    
    foreach ($car_sections as $section) {
        // Extract all the tables inside this .row
        $tables = $xpath->query(".//table[contains(@class, 'table-striped')]//tr", $section);
        
        foreach ($tables as $row) {
            $th = $xpath->query(".//th", $row);
            $td = $xpath->query(".//td", $row);
    
            // Ensure both th and td exist
            if ($th->length > 0 && $td->length > 0) {
                // Get the text content from th and td
                $key = trim($th->item(0)->textContent);
                $value = trim($td->item(0)->textContent);
    
                // Add the key-value pair to the array (merge all rows into a single array)
                $all_data[$key] = $value;
            }
        }
    }
    
    
    // Display the combined data from all rows as key-value pairs in a single array
    echo "<pre>";
    print_r($all_data);
    echo "</pre>";
}

function getCarBrandLogoFromApi($regNo) {
    $url = "https://www.carcheck.co.uk/volkswagen/$regNo";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $output = curl_exec($ch);
    curl_close($ch);

    $dom = new DOMDocument;
    @$dom->loadHTML($output);

    $xpath = new DOMXPath($dom);
    $logo_divs = $xpath->query("//div[contains(@class, 'car-brand-logo')]");

    $logo_url = '';

    foreach ($logo_divs as $div) {
        $style = $div->getAttribute('style');
        if (preg_match('/background-image:url\((\'|")?(.*?)\1?\)/i', $style, $matches)) {
            $logo_url = $matches[2];
            break;
        }
    }

    return $logo_url;
}
?>

<!-- BEGIN: Content -->
<!-- BEGIN: Boxed Tab -->
<div class="intro-y flex items-center py-2">
    <h2 class="text-lg font-medium mr-auto">
        Enter the basic details about the car you want to sell
    </h2>
</div>

<div class="intro-y col-span-12 lg:col-span-6">
    <div class="intro-y box">
        <div id="boxed-tab" class="p-5">
            <div class="preview">
                <ul class="nav nav-tabs" role="tablist">
                    
                    <!----------------- Find your car ------------------->
                    <li id="tab_addnew" class="nav-item" role="presentation"> 
                    <button class="nav-link w-11 py-2 active" data-tw-toggle="pill" data-tw-target="#addnew" type="button" role="tab" aria-controls="addnew" aria-selected="false"> Find your car </button> 
                    </li>
                    <!--
                      <li id="tab_Personal_Details" class="nav-item" role="presentation">
                    <button class="nav-link w-11 py-2 " data-tw-toggle="pill" data-tw-target="#Personal_Details" type="button" role="tab" aria-controls="Personal_Details" aria-selected="false"> Personal Details </button> 
                    </li>
                    
                      <li id="tab_Incomplete" class="nav-item" role="presentation">
                    <button class="nav-link w-11 py-2 " data-tw-toggle="pill" data-tw-target="#Incomplete" type="button" role="tab" aria-controls="Incomplete" aria-selected="false"> Your ad - Incomplete </button> 
                    </li>
                    -->
                </ul>
               <div class="tab-content border-l border-r border-b">
                    <!----------------- Find your car ------------------->
<div id="addnew" class="tab-pane leading-relaxed p-5 active" role="tabpanel" aria-labelledby="tab_addnew">
    <!-- details -->
    <form class="mt-3  " method="POST">
        <!-- input field -->
        <div>
            <label for="crud-form-1" class="inline-block mb-2">Vehicle Registration</label>
           <div class="flex flex-col md:flex-row gap-4  ">
                <input id="crud-form-1" name="regNo" type="text" placeholder="Enter Vehicle Registration Number" class="md:w-96 disabled:bg-slate-100 disabled:cursor-not-allowed transition duration-200 ease-in-out  text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80">
              <!-- btn -->
        <button type="submit" class=" transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary focus:ring-opacity-20 bg-primary border-primary text-white dark:border-primary">Search</button>
           </div>
        </div>
      
    </form>

    <?php
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        // Show loading animation
        echo '<div id="loading" class="flex justify-center items-center my-5">';
        echo '<div class="w-16 h-16 border-4 border-t-4 border-blue-500 border-solid rounded-full animate-spin"></div>';
        echo '</div>';

        // Get vehicle registration number from form
        $regNo = $_POST['regNo'];

        // Fetch and display the car brand logo
        $logoUrl = getCarBrandLogoFromApi($regNo);
        echo '<img class="w-24 h-auto my-5" src="' . htmlspecialchars($logoUrl) . '">';

        // Fetch and display the vehicle details table
        // getVehicleTableDetailFromApi($regNo);
        
        $allData = $objProduct->getVehicleTableDetailFromApi($regNo);
        
        if(count($allData)>0){
            // Start rendering the table
            $data = "";
            $data .= '<div class="overflow-x-auto">';
            $data .= '<table data-tw-merge class="w-full text-left">';
            $data .= '<thead data-tw-merge class="">';
            $data .= '<tr data-tw-merge>';
            $data .= '<th data-tw-merge class="font-medium px-5 py-3 border-b-2 dark:border-darkmode-300 border-l border-r border-t whitespace-nowrap">#</th>';
            $data .= '<th data-tw-merge class="font-medium px-5 py-3 border-b-2 dark:border-darkmode-300 border-l border-r border-t whitespace-nowrap">Vehicle Details</th>';
            $data .= '<th data-tw-merge class="font-medium px-5 py-3 border-b-2 dark:border-darkmode-300 border-l border-r border-t whitespace-nowrap">Information</th>';
            $data .= '</tr>';
            $data .= '</thead>';
            $data .= '<tbody>';
        
            // Display each key-value pair as a row
            $index = 1;
            foreach ($allData as $key => $value) {
                $data .= '<tr data-tw-merge>';
                $data .= '<td data-tw-merge class="px-5 py-3 border-b dark:border-darkmode-300 border-l border-r border-t">' . $index++ . '</td>';
                $data .= '<td data-tw-merge class="px-5 py-3 border-b dark:border-darkmode-300 border-l border-r border-t">' . htmlspecialchars($key) . '</td>';
                $data .= '<td data-tw-merge class="px-5 py-3 border-b dark:border-darkmode-300 border-l border-r border-t">' . htmlspecialchars($value) . '</td>';
                $data .= '</tr>';
            }
        
            $data .= '</tbody>';
            $data .= '</table>';
            $data .= '</div>';
            
            echo $data;
        } else {
            echo "<center><h2>Sorry! data not found.</h2></center>";
        }

        // Hide loading animation
        echo '<script>document.getElementById("loading").style.display = "none";</script>';
    }
    ?>
</div>

                    <!----------------- Personal Details ------------------->
                      <div id="Personal_Details" class="tab-pane leading-relaxed p-5 " role="tabpanel" aria-labelledby="tab_Personal_Details">
                        <div class="overflow-x-auto"> 
                            <div class="mt-5 grid grid-cols-12 gap-6">
                                <div class="intro-y col-span-6 lg:col-span-12">
                                    <form class="form-horizontal form-groups-bordered validate" id="user_form" target="_top" method="post" accept-charset="utf-8" enctype="multipart/form-data">
                                        <?php echo $token; ?>
                                        <!-- BEGIN: Form Layout -->
                                        <div class="intro-y box p-5">
                                            <div class="mt-3">
                                                 <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        First&nbsp;Name
                                                    </div>
                                                    <input data-tw-merge="" name='campaign_name' type="text" placeholder="First Name" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                 <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        Last&nbsp;Name
                                                    </div>
                                                    <input data-tw-merge="" name='campaign_name' type="text" placeholder="Last Name" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>
                                            <!--<div class="mt-3">
                                                <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        Current Mileage
                                                    </div>
                                                    <input data-tw-merge="" name='subject' type="text" placeholder="Current Mileage" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>-->
                                            <div class="mt-5 text-right">
                                                <input type="hidden" name="operation" id="operation" value="Add">
                                                <button data-tw-merge="" type="reset" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary mr-1 w-24">Cancel</button>
                                                <button type="submit" class="btn btn-primary w-24" name="action" id="action" value="Add"> <i class="fa fa-save"></i> &nbsp; Save</button>
                                            </div>
                                        </div>
                                        <!-- END: Form Layout -->
                                    </form>
                                </div>
                                <div class="intro-y col-span-12 lg:col-span-6">
                                    <?php echo "        
                                    <iframe id='myiframe' style='width: 100%; height: 600px;' src=''></iframe>";
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!----------------- Personal Details ------------------->
                      <div id="Incomplete" class="tab-pane leading-relaxed p-5 " role="tabpanel" aria-labelledby="tab_Incomplete">
                        <div class="overflow-x-auto"> 
                            <div class="mt-5 grid grid-cols-12 gap-6">
                                <div class="intro-y col-span-6 lg:col-span-12">
                                    <form class="form-horizontal form-groups-bordered validate" id="user_form" target="_top" method="post" accept-charset="utf-8" enctype="multipart/form-data">
                                        <?php echo $token; ?>
                                        <!-- BEGIN: Form Layout -->
                                        <div class="intro-y box p-5">
                                            <div class="mt-3">
                                                 <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        Registration&nbsp;number
                                                    </div>
                                                    <input data-tw-merge="" name='campaign_name' type="text" placeholder="Registration number" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                 <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        Current&nbsp;Mileage
                                                    </div>
                                                    <input data-tw-merge="" name='campaign_name' type="text" placeholder="Current Mileage" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>
                                            <!--<div class="mt-3">
                                                <div data-tw-merge="" inputgroup="inputGroup" class="flex group input-group">
                                                    <div data-tw-merge="subject-field" class="py-2 px-3 bg-slate-100 border shadow-sm border-slate-200 text-slate-600 dark:bg-darkmode-900/20 dark:border-darkmode-900/20 dark:text-slate-400 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r">
                                                        Current Mileage
                                                    </div>
                                                    <input data-tw-merge="" name='subject' type="text" placeholder="Current Mileage" class="disabled:bg-slate-100 disabled:cursor-not-allowed dark:disabled:bg-darkmode-800/50 dark:disabled:border-transparent [&[readonly]]:bg-slate-100 [&[readonly]]:cursor-not-allowed [&[readonly]]:dark:bg-darkmode-800/50 [&[readonly]]:dark:border-transparent transition duration-200 ease-in-out w-full text-sm border-slate-200 shadow-sm rounded-md placeholder:text-slate-400/90 focus:ring-4 focus:ring-primary focus:ring-opacity-20 focus:border-primary focus:border-opacity-40 dark:bg-darkmode-800 dark:border-transparent dark:focus:ring-slate-700 dark:focus:ring-opacity-50 dark:placeholder:text-slate-500/80 group-[.form-inline]:flex-1 group-[.input-group]:rounded-none group-[.input-group]:[&:not(:first-child)]:border-l-transparent group-[.input-group]:first:rounded-l group-[.input-group]:last:rounded-r group-[.input-group]:z-10" required>
                                                </div>
                                            </div>-->
                                            <div class="mt-5 text-right">
                                                <input type="hidden" name="operation" id="operation" value="Add">
                                                <button data-tw-merge="" type="reset" class="transition duration-200 border shadow-sm inline-flex items-center justify-center py-2 px-3 rounded-md font-medium cursor-pointer focus:ring-4 focus:ring-primary mr-1 w-24">Cancel</button>
                                                <button type="submit" class="btn btn-primary w-24" name="action" id="action" value="Add"> <i class="fa fa-save"></i> &nbsp; Save</button>
                                            </div>
                                        </div>
                                        <!-- END: Form Layout -->
                                    </form>
                                </div>
                                <div class="intro-y col-span-12 lg:col-span-6">
                                    <?php echo "        
                                    <iframe id='myiframe' style='width: 100%; height: 600px;' src=''></iframe>";
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
               
                </div>
            </div>
        </div>
    </div>
</div>

<!-- END: Boxed Tab -->
<?php include("../index/footer.php"); ?>