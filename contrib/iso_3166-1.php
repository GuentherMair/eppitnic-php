<?php
$file = "iso_3166-1.xml";

$myobject = simplexml_load_file ($file);
$myarray  = object2array($myobject);

$tmp = array();

foreach ($myarray["ISO_3166-1_Entry"] as $country)
{
        $name = $country["ISO_3166-1_Country_name"]; 
        $code = $country["ISO_3166-1_Alpha-2_Code_element"];

        // Do something with code and name
        $tmp[] = $code;
}

echo "array('".implode("','", $tmp)."')";

function object2array($object)
{
   $return = NULL;
   if(is_array($object))
   {
       foreach($object as $key => $value)
       $return[$key] = object2array($value);
   } else {
       $var = get_object_vars($object);
       if($var)
       {
           foreach($var as $key => $value)
               $return[$key] = object2array($value);
       } else {
           return strval($object); // strval and everything is fine
       }
   }
   return $return;
}
?>
