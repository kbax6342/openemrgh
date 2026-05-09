/**
 * patient_data_view_model.js
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

function encounter_data(id,date,category)
{
    var self=this;
    self.id=ko.observable(id);
    self.date=ko.observable(date);
    self.category=ko.observable(category);
    return this;
}

function patient_data_view_model(pname,pid,pubpid,str_dob,sex,active_status)
{
    var self=this;
    self.pname=ko.observable(pname);
    self.pid=ko.observable(pid);
    self.pubpid=ko.observable(pubpid);
    self.str_dob=ko.observable(str_dob);
    self.sex=ko.observable(sex || "Unknown");
    self.active_status=ko.observable(active_status || "Active");
    self.active_status_badge=ko.computed(function(){
        var status=(self.active_status() || "Active").toLowerCase();
        if (status === "deceased") {
            return "badge-danger";
        }
        if (status === "inactive") {
            return "badge-warning";
        }
        if (status === "unknown") {
            return "badge-secondary";
        }
        return "badge-success";
    }, self);
    self.patient_picture=ko.computed(function(){
      return webroot_url + '/controller.php' +
             '?document&retrieve' +
             '&patient_id=' + encodeURIComponent(pid) +
             '&document_id=-1' +
             '&as_file=false' +
             '&original_file=true' +
             '&disable_exit=false' +
             '&show_original=true' +
             '&context=patient_picture';
    }, self);

    self.encounterArray=ko.observableArray();
    self.selectedEncounterID=ko.observable();
    self.selectedEncounter=ko.observable();
    self.selectedEncounterID.extend({notify: 'always'});
    self.selectedEncounterID.subscribe(function(newVal)
    {
       for(var encIdx=0;encIdx<self.encounterArray().length;encIdx++)
       {
           var curEnc=self.encounterArray()[encIdx];
           if(curEnc.id()==newVal)
           {

               self.selectedEncounter(curEnc);
               return;
           }
       }
       // No valid encounter ID found, so clear selected encounter;
       self.selectedEncounter(null);
    });
    return this;
}
