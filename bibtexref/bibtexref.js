// Code to draw various statistics in JS using D3.
// Called by the PHP script which manages BibTexRef.
//


function decodeEntities(str)                                       //Needed to map JSON text to proper-formatted HTML 
{							           //(if including accents etc)
  const txt = document.createElement('textarea');
  txt.innerHTML = str;
  return txt.value;
}


function drawBarChart(containerId, inputData)
{
  const minBarWidth = 4;                                           //Min bar width, defined so one can see/brush the bar
  const minBarHeight = 4;                                          //Min bar height, defined so one can see/brush the bar
 
  // Convert object to sorted array
  const data = Object.entries(inputData).map(([label, count]) => ({ label, count: +count }));
  const total = d3.sum(data, d => d.count); 
  const margin = { top: 20, right: 20, bottom: 80, left: 50 };
  const container = document.getElementById(containerId);
  const height = 200;
  const maxBarWidth = 20;     // max width per bar
  const barPadding = 0.1;     // padding between bars (10%)
 

   const tooltip = d3.select('body')
    .append('div')
    .style('position', 'absolute')
    .style('background', 'rgba(230,230,230,0.8)')
    .style('color', 'black')
    .style('padding', '4px 8px')
    .style('border-radius', '4px')
    .style('font-size', '12px')
    .style('pointer-events', 'none')
    .style('opacity', 0);
 
  const nBars = data.length;                                        // Dynamic chart width computation
  const containerWidth = 0.7*container.clientWidth || 300;          // fallback if clientWidth=0
 

  const labels = data.map(d => d.label);
  const maxLabels = Math.floor(containerWidth / 15);                // Determine max #labels to show given min width 15 per label
  const step = Math.max(1, Math.ceil(labels.length / maxLabels));
 
  const x = d3.scaleBand()                                          // Compute scales
              .domain(data.map(d => d.label))
              .range([margin.left, containerWidth - margin.right])
              .padding(barPadding);
  
  let barWidth = x.bandwidth();                                     // Adjust bandwidth if it exceeds maxBarWidth
  if (barWidth > maxBarWidth) 
  {
    const totalBarsWidth = nBars * maxBarWidth;
    const totalPadding = barPadding * (nBars - 1) * maxBarWidth;
    x.range([margin.left, margin.left + totalBarsWidth + totalPadding]);
    barWidth = x.bandwidth();
  }
  if (barWidth<4) barWidth = minBarWidth;
  
  const y = d3.scaleLinear()
              .domain([0, d3.max(data, d => d.count)])
              .nice()
              .range([height - margin.bottom, margin.top]);
 
  const svg = d3.select(container)                                 // Start adding the SVG content i.e. the stuff to draw
                .append('svg')
                .attr('width', containerWidth)
                .attr('height', height);
  
  svg.selectAll('rect')
     .data(data)
     .enter()
     .append('rect')
     .attr('x', d => x(d.label))
     .attr('width', barWidth)
     .attr('y', d => { const h = Math.max(minBarHeight, y(0) - y(d.count)); return y(0) - h; })
     .attr('height', d => { return Math.max(minBarHeight, y(0) - y(d.count)); })
     .attr('fill', 'rgb(32,66,119)')
     .style('pointer-events', 'all')
     .style('pointer-events', 'bounding-box')
     .on('mouseover', function(event, d) { tooltip .style('opacity', 1) .text(`${decodeEntities(d.label)}: ${d.count}`); })
     .on('mousemove', function(event) { tooltip .style('left', (event.pageX + 10) + 'px') .style('top', (event.pageY + 10) + 'px'); })
     .on('mouseout', function() { tooltip.style('opacity', 0); });

 
  const xAxis = svg.append('g')                                     // X axis legend
     .attr('transform', 'translate(0,' + (height - margin.bottom) + ')')
     .call(d3.axisBottom(x));

  xAxis.selectAll('.tick')                                          // Remove labels that are too dense
       .filter((d, i) => i % step !== 0)
       .remove();
  
  xAxis.selectAll('.tick text')                                     // Rotate remaining labels
       .each(function() {
          const t = d3.select(this);
          t.text(decodeEntities(t.text()));
        })
       .attr('transform', 'rotate(-45)')
       .style('text-anchor', 'end');

  svg.append('g')
     .attr('transform', 'translate(' + margin.left + ',0)')
     .call(d3.axisLeft(y)
             .ticks(8)
             .tickFormat(d => Number.isInteger(d) ? d : ''));

  const tickText = svg.select('.tick text').node();                 // Get D3 style used for tick labels, use same for total count below
  const computedStyle = window.getComputedStyle(tickText);

  svg.append('text')                                                // Add total count inside chart top-left
   .attr('x', margin.left + 5)    
   .attr('y', margin.top)     
   .attr('dy', '0.8em')            
   .style('font-size', computedStyle.fontSize)
   .style('font-family', computedStyle.fontFamily)
   .style('font-weight', computedStyle.fontWeight)
   .style('fill', '#757575')   
   .text(`Total: ${total}`);
}

function startRandomGallery(containerId, imageList, count, interval = 3000)
{
  const container = document.getElementById(containerId);
  container.style.setProperty('--cols', count);

  function getRandomImages(n)
  {
    const shuffled = [...imageList].sort(() => 0.5 - Math.random());
    return shuffled.slice(0, n);
  }

  function updateGallery()
  {
    container.innerHTML = "";

    const images = getRandomImages(count);

    images.forEach(src => {
      const div = document.createElement("div");
      const img = new Image();

      img.src = src;
      img.classList.add("fade");

      div.appendChild(img);
      container.appendChild(div);

      setTimeout(() => img.classList.add("visible"), 50);
    });
  }

  updateGallery();
  setInterval(updateGallery, interval);
}

function cycleImages(thumbnailId)                       //Callback for mouse entering thumbnail: starts anim
{
            console.log("cycleImages called with ID:", thumbnailId);

            var container = document.getElementById('thumbC-' + thumbnailId);
            var imgElement = document.getElementById('thumbnail-' + thumbnailId);
            var images = container.getAttribute('data-images').split(',');
            var currentIndex = 0;
            var totalImages = images.length;
            var intervalId = null;                                      // Store interval ID for clearing it later
            
            function updateImage()
            {
                let duration = 400;                                     // Total fadein/out time (msec)
                let frameRate = 60;                                     // Approximate frames per second
                let step = 1 / (duration / (1000 / frameRate));         // Adjust step per frame
                let opacity = 1;                                        // State-var, records current opacity vs time
                let minOpacity = 0.5;                                   // Min opacity for fade effects
                let startTime = performance.now();                      // Track animation time to update opacity

                const fadeOut = (timestamp) => 
                {
                    let elapsed = timestamp - startTime;
                    let progress = Math.min(elapsed / duration, 1);     // Normalize to 0-1
                    let newOpacity = 1 - (progress * (1 - minOpacity));
                    imgElement.style.opacity = newOpacity;              // Decrease opacity

                    if (progress < 1) requestAnimationFrame(fadeOut);   // Ask a redraw
                    else                                                // Fadeout at end: go to next image
                    {
                        currentIndex = (currentIndex + 1) % totalImages;
                        imgElement.src = images[currentIndex];
                        startTime = performance.now();                  // Reset time for fade-in
                        requestAnimationFrame(fadeIn);                  // Restart animation
                    }
                };

                const fadeIn = (timestamp) =>                           
                {
                    let elapsed = timestamp - startTime;
                    let progress = Math.min(elapsed / duration, 1);     // Normalize to 0-1
                    let newOpacity = minOpacity + (progress * (1 - minOpacity));
                    imgElement.style.opacity = newOpacity;              // Increase opacity

                    if (progress < 1) requestAnimationFrame(fadeIn);    // Ask a redraw
                };

                requestAnimationFrame(fadeOut);
            }

            intervalId = setInterval(updateImage, 2000);                // Total duration (ms) a thumbnail shows, including fade effects
           
            container.addEventListener('pointerleave', function() { clearInterval(intervalId);  imgElement.src = images[0]; });
                                                                        // Mouse leaves thumbnail: stop anim, reset to 1st image
            return intervalId;                                          // Return interval ID to stop it later
}
                                                                        

